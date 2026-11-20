<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractSigningRequest extends Model
{
    protected $fillable = [
        'request_id', 'request_token', 'title', 'signer_email',
        'signer_name', 'sender_email', 'sender_name', 'status',
        'document_url', 'signed_pdf_url', 'api_response',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
