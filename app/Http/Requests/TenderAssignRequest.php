<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk reassignment of one or more tenders to a collaborator (or to none).
 *
 * `collaborator_id` nullable → "unassign". Gate `tenders.assign`
 * (manager+) is enforced here in authorize().
 */
class TenderAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->can('tenders.assign');
    }

    public function rules(): array
    {
        return [
            'tender_ids'       => ['required', 'array', 'min:1', 'max:500'],
            'tender_ids.*'     => ['integer', 'exists:tenders,id'],
            'collaborator_id'  => ['nullable', 'integer', 'exists:tender_collaborators,id'],
        ];
    }
}
