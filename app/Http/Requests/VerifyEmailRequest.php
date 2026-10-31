<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/auth/verify-email — body {token}.
 * Validation stricte (CONTRAT-VERIF.md) : token = 64 caractères hexadécimaux.
 * Token malformé -> 422 (comportement FormRequest par défaut, sac d'erreurs).
 * Token bien formé mais inconnu -> 400 géré par le contrôleur (lien_invalide).
 */
class VerifyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // endpoint PUBLIC
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'token.required' => 'Token de vérification requis.',
            'token.regex' => 'Token de vérification invalide (64 caractères hexadécimaux attendus).',
        ];
    }
}
