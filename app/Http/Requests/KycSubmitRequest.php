<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité de POST /api/kyc/submit (dashboard.js) :
 * piece_identite + justificatif_domicile requis, PDF/JPEG/PNG, max 10 Mo
 * (règles affichées par le tunnel étape 3 : PDF, PNG, JPG - Max 10 Mo).
 *
 * NOTE 413 : au-delà de cette validation, la limite physique est celle du
 * serveur PHP (upload_max_filesize / post_max_size — Docker : 2M/8M par
 * défaut, aligné sur 25M via conf.d/zz-fedpawa-uploads.ini). Un body plus
 * gros que post_max_size est rejeté AVANT Laravel (413 brut) ; sous cette
 * limite, la règle max:10240 ci-dessous renvoie le 422 métier.
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
