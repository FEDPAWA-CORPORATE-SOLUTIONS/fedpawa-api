<?php

namespace App\Http\Requests;

/**
 * Parité AuthController::register (PHP) — mêmes règles, mêmes messages.
 * Ordre des vérifications PHP : champs manquants (email, mot_de_passe, nom,
 * entreprise) → téléphone → e-mail → robustesse mot de passe.
 */
class RegisterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'nom' => ['required'],
            'entreprise' => ['required'],
            'email' => ['required', 'email:filter'], // FILTER_VALIDATE_EMAIL côté PHP
            'mot_de_passe' => ['required', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/'],
            'telephone' => ['required', 'regex:/^\+?[0-9\s().-]{6,25}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Champs manquants',
            'entreprise.required' => 'Champs manquants',
            'email.required' => 'Champs manquants',
            'email.email' => 'Adresse e-mail invalide',
            'mot_de_passe.required' => 'Champs manquants',
            'mot_de_passe.regex' => 'Mot de passe trop faible (min. 8 caractères, 1 majuscule, 1 chiffre)',
            'telephone.required' => 'Numéro de téléphone invalide (ex: +237 6XX XX XX XX)',
            'telephone.regex' => 'Numéro de téléphone invalide (ex: +237 6XX XX XX XX)',
        ];
    }

    /** PHP valide trim($telephone) — on normalise avant validation. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'telephone' => trim((string) $this->input('telephone', '')),
        ]);
    }
}
