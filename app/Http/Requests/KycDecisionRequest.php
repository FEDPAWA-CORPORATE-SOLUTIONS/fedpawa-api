<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité de POST /api/admin/kyc/approve|reject : id entier requis,
 * motif optionnel (rejet uniquement).
 */
class KycDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // garde admin gérée par middleware policy
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'min:1'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'id.required' => 'ID invalide',
            'id.integer' => 'ID invalide',
            'id.min' => 'ID invalide',
            'motif.string' => 'Le motif doit être un texte.',
            'motif.max' => 'Le motif ne doit pas dépasser 500 caractères.',
        ];
    }
}
