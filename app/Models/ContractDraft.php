<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractDraft extends Model
{
    protected $table = 'contract_drafts';
    public $timestamps = true;

    protected $fillable = [
        'client_id', 'signer_email', 'payer_email', 'flow_token',
        'statut', 'pack', 'prix', 'contrat_data', 'hash_brouillon',
        'hash_sha256', 'signed_pdf_path', 'ip_address', 'user_agent',
        'session_id', 'paiement_transaction_id', 'otp_verified_at',
    ];

    protected $casts = [
        'prix' => 'float',
        'contrat_data' => 'array',
        'otp_verified_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
