<?php

namespace App\Http\Requests;

/** Parité AuthController::adminLogin (PHP) : « Champs manquants » si email/mot_de_passe absents. */
class AdminLoginRequest extends ApiFormRequest
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
