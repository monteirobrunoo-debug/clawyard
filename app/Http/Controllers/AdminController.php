<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    // Users list
    public function users(Request $request)
    {
        $query = User::latest();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        $users = $query->paginate(20);

        return view('admin.users', compact('users'));
    }

    // Create user
    public function createUser(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:8',
            'role'     => 'required|in:admin,manager,user,guest',
        ]);

        $created = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'role'      => $request->role,
            'is_active' => true,
        ]);

        \App\Models\UserAdminEvent::recordCreate($created->id, auth()->id(), [
            'name'  => $created->name,
            'email' => $created->email,
            'role'  => $created->role,
        ]);

        return back()->with('success', 'Utilizador criado com sucesso!');
    }

    // Update user
    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role'  => 'required|in:admin,manager,user,guest',
        ]);

        $data = [
            'name'      => $request->name,
            'email'     => $request->email,
            'role'      => $request->role,
            'is_active' => $request->boolean('is_active'),
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return back()->with('success', 'Utilizador atualizado!');
    }

    // Toggle active
    public function toggleUser(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'Nao pode desativar a sua propria conta.']);
        }

        $user->update(['is_active' => !$user->is_active]);

        // Append-only audit (separate from laravel.log so retention can
        // outlive log rotation and so SOC2 auditors can SQL-query history).
        \App\Models\UserAdminEvent::recordActivation(
            $user->id, auth()->id(), (bool) $user->is_active
        );

        return back()->with('success', $user->is_active ? 'Utilizador ativado!' : 'Utilizador bloqueado!');
    }

    /**
     * Fast role flip between `user` ↔ `manager`.
     *
     * Rationale: the user wanted a one-click way to designate "super-users"
     * from the shares dashboard — people who can see every tender, every
     * share, and can create new shares for clients. Under the hood that IS
     * the `manager` role (isManager() unlocks tenders.collaborators, the
     * tender overview, and the shares admin routes). Instead of inventing
     * a parallel flag we just flip the existing role, so every gate in the
     * app stays consistent.
     *
     * Rules:
     *   - admin can promote/demote anyone except themselves (can't self-
     *     demote — would lock the last admin out).
     *   - `admin` rows are left untouched by this endpoint (admins are
     *     already above manager — use /admin/users to touch those).
     *   - `guest` rows are also left untouched; guests are a deliberate
     *     read-only class, promoting them would be surprising.
     *
     * Returns JSON so the shares dashboard can swap the chip colour in-place.
     */
    public function togglePromote(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json([
                'ok'    => false,
                'error' => 'Não podes alterar a tua própria role — pede a outro admin.',
            ], 422);
        }

        if (!in_array($user->role, ['user', 'manager'], true)) {
            return response()->json([
                'ok'    => false,
                'error' => "Esta acção só se aplica a roles `user` e `manager`. O {$user->email} é `{$user->role}`.",
            ], 422);
        }

        $oldRole = $user->role;
        $newRole = $oldRole === 'manager' ? 'user' : 'manager';
        $user->update(['role' => $newRole]);

        // Two writes: laravel.log (operational tail) AND user_admin_events
        // (durable, queryable audit). The duplication is deliberate — the
        // log is for ops humans grepping live, the table is for compliance.
        \Illuminate\Support\Facades\Log::info('Admin: role promoted/demoted', [
            'target_user_id' => $user->id,
            'email'          => $user->email,
            'from_role'      => $oldRole,
            'to_role'        => $newRole,
            'by_user_id'     => auth()->id(),
        ]);
        \App\Models\UserAdminEvent::recordRoleChange($user->id, auth()->id(), $oldRole, $newRole);

        return response()->json([
            'ok'         => true,
            'role'       => $newRole,
            'is_manager' => $newRole === 'manager',
            'label'      => $newRole === 'manager' ? 'Super-user' : 'User normal',
        ]);
    }

    // Delete user
    public function deleteUser(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'Nao pode apagar a sua propria conta.']);
        }

        // Snapshot identifying fields BEFORE delete — once the row is
        // gone the FK on user_admin_events keeps the event but the
        // payload is the only remaining record of who they were.
        $snapshot = [
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
        ];
        $deletedId = $user->id;

        $user->delete();

        \App\Models\UserAdminEvent::recordDelete($deletedId, auth()->id(), $snapshot);

        return back()->with('success', 'Utilizador removido!');
    }

    // View conversations
    public function conversations(Request $request)
    {
        $conversations = Conversation::with(['messages'])
            ->latest()
            ->paginate(30);

        return view('admin.conversations', compact('conversations'));
    }

    // View single conversation
    public function conversation(Conversation $conversation)
    {
        $conversation->load('messages');
        return view('admin.conversation', compact('conversation'));
    }

    // Stats
    public function stats()
    {
        $stats = [
            'total_users'         => User::count(),
            'active_users'        => User::where('is_active', true)->count(),
            'total_conversations' => Conversation::count(),
            'total_messages'      => \App\Models\Message::count(),
            'users_by_role'       => User::selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
        ];

        return view('admin.stats', compact('stats'));
    }

    // ── Per-user agent access control ────────────────────────────────────
    /**
     * Matrix view of every active user × every agent. Cells are
     * clickable to flip a single permission — the click POSTs to
     * `toggleAgentAccess` and we re-render with the new state.
     *
     * Why a matrix vs N per-user pages: the admin's mental model when
     * onboarding a new persona is "this person is a vendor like the
     * other vendor" — fastest to ack across users on a single page,
     * not click-through-to-each-profile.
     */
    public function agentAccess()
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['user', 'manager', 'admin'])   // skip 'guest' — explicit minimal-access tier
            ->orderBy('role')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'allowed_agents']);

        $agents = collect(\App\Services\AgentCatalog::byKey())
            ->reject(fn($a, $key) => in_array($key, ['auto', 'orchestrator'], true))   // routing meta — always available
            ->values()
            ->all();

        return view('admin.agent-access', [
            'users'   => $users,
            'agents'  => $agents,
            'presets' => array_keys(User::AGENT_PRESETS),
        ]);
    }

    /**
     * Toggle one (user, agent) pair. JSON response so the matrix can
     * patch the cell in-place without a full reload.
     */
    public function toggleAgentAccess(Request $request, User $user, string $agentKey)
    {
        if ($user->isAdmin()) {
            return response()->json([
                'ok' => false,
                'error' => 'Admins têm sempre acesso a todos os agentes.',
            ], 422);
        }
        $catalog = \App\Services\AgentCatalog::byKey();
        if (!isset($catalog[$agentKey])) {
            return response()->json(['ok' => false, 'error' => "Agente desconhecido: {$agentKey}"], 422);
        }

        $current = $user->allowed_agents;

        // First click on a NULL row materialises the whitelist as
        // "every agent minus this one" — same intent-driven UX as the
        // tender source toggle. Click=block.
        if ($current === null) {
            $allKeys = array_keys($catalog);
            $allKeys = array_values(array_diff($allKeys, ['auto', 'orchestrator']));
            $next = array_values(array_diff($allKeys, [$agentKey]));
        } elseif (in_array($agentKey, $current, true)) {
            $next = array_values(array_filter($current, fn($k) => $k !== $agentKey));
        } else {
            $next = array_values(array_unique(array_merge($current, [$agentKey])));
        }

        // Collapse to NULL when every non-meta agent is in the list —
        // cleaner sentinel state than carrying a 30-element array.
        $allKeys = array_values(array_diff(array_keys($catalog), ['auto', 'orchestrator']));
        if (!empty($next) && count(array_diff($allKeys, $next)) === 0) {
            $next = null;
        }

        $user->allowed_agents = $next;
        $user->save();

        \App\Models\UserAdminEvent::create([
            'target_user_id' => $user->id,
            'actor_user_id'  => auth()->id(),
            'event_type'     => 'agent_access_toggle',
            'payload'        => ['agent' => $agentKey, 'now_allowed' => $user->canUseAgent($agentKey)],
        ]);

        return response()->json([
            'ok'             => true,
            'allowed_agents' => $next,
            'now_allowed'    => $user->canUseAgent($agentKey),
            'mode'           => $next === null ? 'unrestricted' : (empty($next) ? 'blocked' : 'whitelist'),
        ]);
    }

    /**
     * Apply one of User::AGENT_PRESETS to a user. Idempotent — the
     * preset replaces whatever was there.
     */
    public function applyAgentPreset(Request $request, User $user, string $preset)
    {
        if ($user->isAdmin()) {
            return back()->withErrors(['preset' => 'Admins têm acesso total — preset não aplicável.']);
        }

        try {
            $user->applyAgentPreset($preset);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['preset' => $e->getMessage()]);
        }

        \App\Models\UserAdminEvent::create([
            'target_user_id' => $user->id,
            'actor_user_id'  => auth()->id(),
            'event_type'     => 'agent_preset_applied',
            'payload'        => ['preset' => $preset, 'allowed_agents' => $user->allowed_agents],
        ]);

        return back()->with('status', "Preset \"{$preset}\" aplicado a \"{$user->name}\".");
    }
}
