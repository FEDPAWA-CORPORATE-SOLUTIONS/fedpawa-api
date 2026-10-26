<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité DashboardController@updateProfile (legacy) :
 * nom + email requis ; email valide et unique hors client courant (409).
 */
class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'entreprise' => ['nullable', 'string', 'max:190'],
            'telephone' => ['nullable', 'string', 'max:40'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Nom et e-mail requis',
            'email.required' => 'Nom et e-mail requis',
            'email.email' => 'E-mail invalide',
        ];
    }
}
