<?php

namespace App\Http\Requests;

use App\Models\Tender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates inline edits to a single tender.
 *
 * Regular users (assignee) and managers hit the same endpoint — the gate
 * `tenders.update` decides who passes. Field-level constraints are the
 * same for both roles; we do NOT narrow the field set for regular users
 * because the user explicitly asked for "can edit all fields" (except
 * delete, which is a separate action).
 *
 * Notes: `notes` is mutable via this form. Observations — the append-only
 * audit trail — go through TenderController::observe instead.
 */
class TenderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('tenders.update', $this->route('tender'));
    }

    public function rules(): array
    {
        return [
            'title'                  => ['sometimes', 'string', 'max:500'],
            'type'                   => ['nullable', 'string', 'max:64'],
            'purchasing_org'         => ['nullable', 'string', 'max:255'],
            'status'                 => ['sometimes', 'string', Rule::in([
                Tender::STATUS_PENDING, Tender::STATUS_EM_TRATAMENTO, Tender::STATUS_SUBMETIDO,
                Tender::STATUS_AVALIACAO, Tender::STATUS_CANCELADO, Tender::STATUS_NAO_TRATAR,
                Tender::STATUS_GANHO, Tender::STATUS_PERDIDO,
            ])],
            'priority'               => ['nullable', 'string', 'max:16'],
            'sap_opportunity_number' => ['nullable', 'string', 'max:64'],
            'offer_value'            => ['nullable', 'numeric', 'min:0'],
            'currency'               => ['nullable', 'string', 'size:3'],
            'time_spent_hours'       => ['nullable', 'numeric', 'min:0'],
            'notes'                  => ['nullable', 'string', 'max:65535'],
            'result'                 => ['nullable', 'string', 'max:64'],
            'deadline_at'            => ['nullable', 'date'],
        ];
    }
}
