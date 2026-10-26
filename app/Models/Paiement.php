<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Paiement extends Model
{
    protected $table = 'paiements';
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'montant', 'mode_paiement', 'transaction_id', 'date', 'statut',
    ];

    protected $casts = [
        'montant' => 'float',
        'date' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
