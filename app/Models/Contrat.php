<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contrat extends Model
{
    protected $table = 'contrats';
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'type', 'fichier', 'date_signature',
    ];

    protected $casts = [
        'date_signature' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
