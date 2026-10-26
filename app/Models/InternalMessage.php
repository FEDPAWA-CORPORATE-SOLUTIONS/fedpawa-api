<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternalMessage extends Model
{
    protected $table = 'internal_messages';
    public $timestamps = false;

    protected $fillable = [
        'sender_id', 'sender_type', 'receiver_id', 'receiver_type',
        'content', 'is_read', 'created_at',
    ];

    protected $casts = [
        'is_read' => 'integer',
        'created_at' => 'datetime',
    ];
}
