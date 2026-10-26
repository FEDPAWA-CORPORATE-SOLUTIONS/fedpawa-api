<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base des FormRequests API — parité contrat PHP source :
 * une erreur de validation renvoie {"error": "<message>"} avec code 400,
 * jamais le sac d'erreurs 422 par défaut de Laravel.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['error' => $validator->errors()->first()], 400)
        );
    }
}
