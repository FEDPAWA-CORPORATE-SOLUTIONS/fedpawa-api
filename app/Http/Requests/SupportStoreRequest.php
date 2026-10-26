<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité SupportController@store (legacy) :
 * - sujet + message requis, trim, 150 / 5000 max ;
 * - message d'erreur unique « Champs requis » / longueur spécifique.
 */
class SupportStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gardé d'auth géré par route middleware auth:sanctum
    }

    public function rules(): array
    {
        return [
            'sujet' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'sujet.required' => 'Champs requis',
            'message.required' => 'Champs requis',
            'sujet.max' => 'Sujet (150 max) ou message (5000 max) trop long',
            'message.max' => 'Sujet (150 max) ou message (5000 max) trop long',
        ];
    }
}
