<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité de POST /api/souscription (tunnel.js submitPayload) :
 * service, plan, pack, planLabel, prix, options, paymentMethod,
 * momo_phone, momo_operator, nom, name, email, telephone + champs L022
 * (rccm, niu, adresse, representant, qualite, capital, entreprise).
 */
class SouscriptionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // garde d'authentification gérée par middleware sanctum
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'pack' => ['required', 'string', 'max:255'],
            'nom' => ['required', 'string', 'max:255'],
            'prix' => ['required', 'numeric', 'gte:0'],
            'entreprise' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:32'],
            'service' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:255'],
            'planLabel' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
            'options.*' => ['array'],
            'paymentMethod' => ['nullable', 'string', 'max:32'],
            'momo_phone' => ['nullable', 'string', 'max:32'],
            'momo_operator' => ['nullable', 'string', 'max:32'],
            'rccm' => ['nullable', 'string', 'max:255'],
            'niu' => ['nullable', 'string', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'representant' => ['nullable', 'string', 'max:255'],
            'qualite' => ['nullable', 'string', 'max:255'],
            'capital' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'email.required' => 'Données de souscription incomplètes.',
            'email.email' => 'Adresse e-mail invalide.',
            'pack.required' => 'Données de souscription incomplètes.',
            'nom.required' => 'Données de souscription incomplètes.',
            'prix.required' => 'Prix invalide.',
            'prix.numeric' => 'Prix invalide.',
            'prix.gt' => 'Prix invalide.',
            'prix.gte' => 'Prix invalide.',
        ];
    }
}
