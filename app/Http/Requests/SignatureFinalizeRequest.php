<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité de POST /api/signature (fedpawa-pay.js) :
 * sub_id, client_id, otp requis.
 */
class SignatureFinalizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'otp' => ['required', 'string'],
            'sub_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'otp.required' => 'Champs manquants',
            'sub_id.required' => 'Champs manquants',
            'client_id.required' => 'Champs manquants',
        ];
    }
}
