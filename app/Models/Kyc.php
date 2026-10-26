<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kyc extends Model
{
    protected $table = 'kyc';
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'piece_identite', 'statuts', 'justificatif_domicile',
        'description_activite', 'date_soumission',
    ];

    protected $casts = [
        'date_soumission' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
