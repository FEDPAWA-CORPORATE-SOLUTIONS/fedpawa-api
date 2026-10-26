<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Facture extends Model
{
    protected $table = 'factures';
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'numero', 'montant', 'date', 'lien_pdf',
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
