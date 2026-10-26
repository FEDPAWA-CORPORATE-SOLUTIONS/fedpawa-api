<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité de POST /api/kyc/submit (dashboard.js) :
 * piece_identite + justificatif_domicile requis, PDF/JPEG/PNG, max 10 Mo
 * (règles affichées par le tunnel étape 3 : PDF, PNG, JPG - Max 10 Mo).
 */
class KycSubmitRequest extends FormRequest
{
    public const MAX_BYTES = 10485760; // 10 Mo

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'piece_identite' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) (self::MAX_BYTES / 1024)],
            'justificatif_domicile' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) (self::MAX_BYTES / 1024)],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'piece_identite.required' => "Pièce d'identité et justificatif de domicile requis",
            'justificatif_domicile.required' => "Pièce d'identité et justificatif de domicile requis",
            'piece_identite.mimes' => 'Format de fichier non autorisé.',
            'justificatif_domicile.mimes' => 'Format de fichier non autorisé.',
            'piece_identite.max' => 'Fichier trop volumineux (max 10 Mo).',
            'justificatif_domicile.max' => 'Fichier trop volumineux (max 10 Mo).',
        ];
    }
}
