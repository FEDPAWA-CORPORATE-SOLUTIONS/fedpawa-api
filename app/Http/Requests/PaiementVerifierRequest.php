<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Parité de POST /api/paiement/verifier : sub_id requis. */
class PaiementVerifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'sub_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'sub_id.required' => 'Champs manquants',
            'sub_id.integer' => 'Champs manquants',
        ];
    }
}
