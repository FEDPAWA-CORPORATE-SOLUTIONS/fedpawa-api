<?php

namespace App\Http\Requests;

/**
 * Parité AdminController::templateOtpVerify : OTP à 6 chiffres (chiffres
 * seulement acceptés, 'otp' requis). Validation max au-delà du masque
 * \D géré dans le contrôleur (parité source : strlen !== 6 → 400).
 */
class TemplateOtpVerifyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'Code à 6 chiffres requis',
            'otp.regex' => 'Code à 6 chiffres requis',
        ];
    }
}
