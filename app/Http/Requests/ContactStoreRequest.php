<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité ContactController@store (legacy PHP) :
 * - nom requis, 120 max ; email valide, 190 max ;
 * - téléphone optionnel, regex /^[+0-9\s().-]{6,25}$/ ;
 * - sujet requis 150 max ; message requis 5000 max ;
 * - honeypot « site » : accepté mais ignoré (message de succès renvoyé).
 * Messages d'erreur identiques au legacy ('Nom invalide', etc.).
 */
class ContactStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // endpoint public anonyme
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9\s().-]{6,25}$/'],
            'sujet' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'site' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Nom invalide',
            'nom.max' => 'Nom invalide',
            'email.required' => 'E-mail invalide',
            'email.email' => 'E-mail invalide',
            'email.max' => 'E-mail invalide',
            'telephone.regex' => 'Téléphone invalide',
            'sujet.required' => 'Sujet requis',
            'sujet.max' => 'Sujet requis',
            'message.required' => 'Message requis (5000 caractères max)',
            'message.max' => 'Message requis (5000 caractères max)',
        ];
    }
}
