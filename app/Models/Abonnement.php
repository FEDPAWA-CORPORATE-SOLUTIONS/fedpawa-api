<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Abonnement extends Model
{
    protected $table = 'abonnements';
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'pack', 'prix', 'engagement', 'date_debut', 'date_fin', 'statut',
    ];

    protected $casts = [
        'prix' => 'float',
        'engagement' => 'integer',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
