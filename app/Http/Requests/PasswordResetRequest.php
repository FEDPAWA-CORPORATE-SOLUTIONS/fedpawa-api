<?php

namespace App\Http\Requests;

/** Parité AuthController::requestPasswordReset (PHP) : email requis, sans contrôle de format. */
class PasswordResetRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email requis',
        ];
    }
}
