<?php

namespace App\Http\Requests;

/**
 * Parité AdminController::ticketReply : ticket_id entier > 0, reponse
 * non vide max 5000 caractères (messages 400 du source).
 */
class TicketReplyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'ticket_id' => ['required', 'integer', 'min:1'],
            'reponse' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'ticket_id.required' => 'Champs requis',
            'reponse.required' => 'Champs requis',
            'reponse.max' => 'Réponse trop longue (max 5000)',
        ];
    }
}
