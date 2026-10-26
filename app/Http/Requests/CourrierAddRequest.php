<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité CourrierController@add (legacy, admin) : client_id + scan_path requis.
 */
class CourrierAddRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
            'scan_path' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Champs manquants',
            'scan_path.required' => 'Champs manquants',
        ];
    }
}
