<?php

namespace App\Http\Requests;

/**
 * Parité AdminController::updateTemplate : content chaîne non vide.
 * La longueur minimale (50) reste côté contrôleur pour renvoyer le
 * message exact 'Contenu de template invalide' (400, parité source).
 */
class TemplateUpdateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Contenu de template invalide',
            'content.string' => 'Contenu de template invalide',
        ];
    }
}
