<?php

namespace App\Http\Requests;

/**
 * Parité AuthController::verifyOtp (PHP) : « Données invalides » si otp absent
 * (l'e-mail remplace la session $_SESSION['reset_email'] pour l'API stateless).
 */
class VerifyOtpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required'],
            'otp' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Données invalides',
            'otp.required' => 'Données invalides',
        ];
    }
}
