<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }

    /**
     * Checkboxes HTML não enviam o campo quando UN-checked. Sem isto, o
     * validated() não inclui as keys e o user permanece com o valor
     * actual em vez de ficar false. Force-merge boolean para garantir
     * que destigar funciona.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            weekly_digest_enabled   => $this->boolean(weekly_digest_enabled),
            daily_digest_enabled    => $this->boolean(daily_digest_enabled),
            deadline_alerts_enabled => $this->boolean(deadline_alerts_enabled),
        ]);
    }
}
