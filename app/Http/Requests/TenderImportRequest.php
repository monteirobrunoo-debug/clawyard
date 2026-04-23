<?php

namespace App\Http\Requests;

use App\Services\TenderImport\TenderImportService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the tender-import upload form.
 *
 * Gate `tenders.import` is enforced at the route middleware level; this
 * request only handles payload shape (file type + known source).
 */
class TenderImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('tenders.import');
    }

    public function rules(): array
    {
        $sources = app(TenderImportService::class)->availableSources();

        return [
            'source' => ['required', 'string', Rule::in($sources)],
            // .xlsx only for now — all current importers use PhpSpreadsheet
            'file'   => ['required', 'file', 'max:30720', 'mimes:xlsx,xls'],
        ];
    }

    public function messages(): array
    {
        return [
            'source.in'   => 'Fonte desconhecida. Fontes disponíveis: :values.',
            'file.mimes'  => 'O ficheiro tem de ser .xlsx ou .xls.',
            'file.max'    => 'Ficheiro acima de 30 MB — divide em dois ou contacta o admin.',
        ];
    }
}
