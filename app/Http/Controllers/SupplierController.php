<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\SupplierCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * /suppliers — H&P approved supplier directory.
 *
 * Public read-only access for any authenticated user (the team needs
 * to look up "who do we already work with for X?"). Manager+ for
 * write operations (create / update / blacklist).
 */
class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q'         => trim((string) $request->input('q', '')),
            'category'  => (string) $request->string('category')->trim()->value(),
            'status'    => (string) $request->string('status')->trim()->value(),
            'min_iqf'   => $request->filled('min_iqf') ? (float) $request->input('min_iqf') : null,
            'source'    => (string) $request->string('source')->trim()->value(),
        ];

        $sort = $request->string('sort')->trim()->value() ?: 'name';
        $dir  = strtolower($request->string('dir')->trim()->value()) === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['name', 'iqf_score', 'last_contacted_at', 'total_outreach', 'created_at'];
        if (!in_array($sort, $allowedSort, true)) $sort = 'name';

        $query = Supplier::query();
        if ($filters['q'] !== '')        $query->search($filters['q']);
        if ($filters['category'] !== '') $query->inCategory($filters['category']);
        if ($filters['status'] !== '')   $query->where('status', $filters['status']);
        if ($filters['min_iqf'] !== null) $query->where('iqf_score', '>=', $filters['min_iqf']);
        if ($filters['source'] !== '')   $query->where('source', $filters['source']);

        // NULL-safe ordering — null IQFs sink last when sorting desc.
        if ($sort === 'iqf_score') {
            $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
            $query->orderByRaw("iqf_score IS NULL, iqf_score {$dirSql}");
        } elseif ($sort === 'last_contacted_at') {
            $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
            $query->orderByRaw("last_contacted_at IS NULL, last_contacted_at {$dirSql}");
        } else {
            $query->orderBy($sort, $dir);
        }

        $suppliers = $query->paginate(50)->withQueryString();

        // Aggregate counters for the header chips.
        $counts = [
            'total'      => Supplier::count(),
            'approved'   => Supplier::where('status', Supplier::STATUS_APPROVED)->count(),
            'pending'    => Supplier::where('status', Supplier::STATUS_PENDING)->count(),
            'blacklist'  => Supplier::where('status', Supplier::STATUS_BLACKLIST)->count(),
            'with_email' => Supplier::whereNotNull('primary_email')->count(),
        ];

        return view('suppliers.index', [
            'suppliers'  => $suppliers,
            'filters'    => $filters,
            'sort'       => $sort,
            'dir'        => $dir,
            'counts'     => $counts,
            'categories' => SupplierCategories::options(),
            'canEdit'    => Auth::user()?->isManager() ?? false,
        ]);
    }

    public function show(Supplier $supplier)
    {
        return view('suppliers.show', [
            'supplier'   => $supplier,
            'categories' => SupplierCategories::TOP_LEVEL,
            'canEdit'    => Auth::user()?->isManager() ?? false,
        ]);
    }

    public function create()
    {
        $this->authorizeManager();
        return view('suppliers.create', [
            'categories' => SupplierCategories::options(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeManager();

        $data = $this->validateRequest($request);

        $slug = Supplier::makeSlug($data['name']);
        if ($slug === '') {
            return back()->withErrors(['name' => 'Não foi possível gerar slug — verifica o nome.'])->withInput();
        }

        $existing = Supplier::where('slug', $slug)->first();
        if ($existing) {
            return redirect()->route('suppliers.show', $existing)
                ->with('status', 'Fornecedor já existia (matched por slug) — abriste a versão existente para editar.');
        }

        $sup = new Supplier($this->normalisePayload($data));
        $sup->slug   = $slug;
        $sup->source = Supplier::SOURCE_MANUAL;
        $sup->save();

        return redirect()->route('suppliers.show', $sup)
            ->with('status', 'Fornecedor criado.');
    }

    public function update(Request $request, Supplier $supplier)
    {
        $this->authorizeManager();

        $data = $this->validateRequest($request, updating: true);
        $supplier->fill($this->normalisePayload($data));
        $supplier->save();

        return redirect()->route('suppliers.show', $supplier)
            ->with('status', 'Fornecedor actualizado.');
    }

    /**
     * JSON endpoint for the tender "sugerir fornecedores" button —
     * returns up to N approved suppliers matching one or more category
     * codes, optionally filtered by minimum IQF. Powers suggestion #1.
     */
    public function suggest(Request $request)
    {
        $codes = (array) $request->input('categories', []);
        $codes = array_values(array_filter(array_map('strval', $codes)));
        if (empty($codes)) return response()->json(['suppliers' => []]);

        $minIqf = (float) $request->input('min_iqf', 2.5);
        $limit  = max(1, min(20, (int) $request->input('limit', 8)));

        $query = Supplier::contactable()
            ->where(function ($w) use ($codes) {
                foreach ($codes as $c) $w->orWhere(fn($q) => $q->inCategory($c));
            })
            ->where(fn($w) => $w->whereNull('iqf_score')->orWhere('iqf_score', '>=', $minIqf))
            ->orderByRaw('primary_email IS NULL')   // ones with email first
            ->orderByRaw('iqf_score IS NULL, iqf_score DESC')
            ->orderBy('name')
            ->limit($limit);

        return response()->json([
            'suppliers' => $query->get()->map(fn(Supplier $s) => [
                'id'             => $s->id,
                'name'           => $s->name,
                'slug'           => $s->slug,
                'primary_email'  => $s->primary_email,
                'iqf_score'      => $s->iqf_score !== null ? (float) $s->iqf_score : null,
                'categories'     => $s->categories ?? [],
                'subcategories'  => $s->subcategories ?? [],
                'brands'         => $s->brands ?? [],
                'has_email'      => !empty($s->primary_email),
            ])->all(),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function authorizeManager(): void
    {
        $u = Auth::user();
        if (!$u || !$u->isManager()) abort(403, 'Apenas managers podem editar o directório.');
    }

    private function validateRequest(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'legal_name'        => ['nullable', 'string', 'max:255'],
            'country_code'      => ['nullable', 'string', 'max:8'],
            'website'           => ['nullable', 'url', 'max:255'],
            'primary_email'     => ['nullable', 'email:filter', 'max:255'],
            'additional_emails' => ['nullable', 'string', 'max:2000'],   // textarea, comma/newline separated
            'phones'            => ['nullable', 'string', 'max:1000'],
            'iqf_score'         => ['nullable', 'numeric', 'min:0', 'max:5'],
            'status'            => ['required', Rule::in([Supplier::STATUS_APPROVED, Supplier::STATUS_PENDING, Supplier::STATUS_BLACKLIST])],
            'categories'        => ['nullable', 'string', 'max:255'],     // comma-separated
            'subcategories'     => ['nullable', 'string', 'max:1000'],
            'brands'            => ['nullable', 'string', 'max:1000'],
            'notes'             => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function normalisePayload(array $data): array
    {
        $splitCsv = function ($s): array {
            if (!$s) return [];
            $parts = preg_split('/[\s,;\n]+/u', (string) $s) ?: [];
            return array_values(array_filter(array_map('trim', $parts)));
        };

        $data['additional_emails'] = $splitCsv($data['additional_emails'] ?? null);
        $data['phones']            = $splitCsv($data['phones']            ?? null);
        $data['categories']        = $splitCsv($data['categories']        ?? null);
        $data['subcategories']     = $splitCsv($data['subcategories']     ?? null);
        $data['brands']            = $splitCsv($data['brands']            ?? null);

        // Empty arrays → null so the column is genuinely empty, not "[]".
        foreach (['additional_emails','phones','categories','subcategories','brands'] as $k) {
            if (empty($data[$k])) $data[$k] = null;
        }

        return $data;
    }
}
