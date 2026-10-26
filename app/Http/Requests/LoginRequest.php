<?php

namespace App\Http\Requests;

/**
 * Parité AuthController::login (PHP) : isset(email, mot_de_passe) requis,
 * message unique « Champs manquants ». Pas de contrôle de format côté PHP.
 */
class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required'],
            'mot_de_passe' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Champs manquants',
            'mot_de_passe.required' => 'Champs manquants',
        ];
    }
}
