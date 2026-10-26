<?php

namespace App\Http\Requests;

/**
 * Parité AuthController::resetPassword (PHP) : nouveau mot de passe requis,
 * même règle de robustesse que le PHP (min 8, 1 majuscule, 1 chiffre).
 * L'e-mail + OTP (équivalents stateless de la session) sont contrôlés dans
 * le contrôleur avec le 403 « Session de réinitialisation non autorisée ».
 */
class ResetPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'mot_de_passe' => ['required', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'mot_de_passe.required' => 'Nouveau mot de passe requis',
            'mot_de_passe.regex' => 'Mot de passe trop faible (min. 8 caractères, 1 majuscule, 1 chiffre)',
        ];
    }
}
