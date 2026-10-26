<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSupport extends Model
{
    protected $table = 'tickets_support';
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'sujet', 'message', 'statut', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
