<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Courrier extends Model
{
    protected $table = 'courriers';
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'date_reception', 'scan', 'statut',
    ];

    protected $casts = [
        'date_reception' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
