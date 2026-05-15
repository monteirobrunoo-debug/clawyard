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
            // 'status' removido 2026-05-15: o estado do concurso é agora
            // derivado SEMPRE do SAP (campo opcional manual descontinuado).
            // Ver Tender::sapStageLabel() — single source of truth.
            // Mantemos a coluna na DB por agora para backwards compat com
            // tenders sem opportunity SAP ligada.
            'priority'               => ['nullable', 'string', 'max:16'],
            'sap_opportunity_number' => ['nullable', 'string', 'max:64'],
            'offer_value'            => ['nullable', 'numeric', 'min:0'],
            'currency'               => ['nullable', 'string', 'size:3'],
            'time_spent_hours'       => ['nullable', 'numeric', 'min:0'],
            'notes'                  => ['nullable', 'string', 'max:65535'],
            'result'                 => ['nullable', 'string', 'max:64'],
            'deadline_at'            => ['nullable', 'date'],
            // Confidential flag — when true, blocks LLM/web augmentation
            // for this tender. See migration 2026_04_30_000004.
            'is_confidential'        => ['nullable', 'boolean'],
        ];
    }

    /**
     * HTML <input type="checkbox"> sends the value only when checked,
     * so an unchecked box arrives as a missing key. Normalise to false
     * before the validator/fill pass so the model gets the correct value.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('is_confidential')) {
            $this->merge(['is_confidential' => false]);
        } else {
            $this->merge(['is_confidential' => $this->boolean('is_confidential')]);
        }
    }
}
