<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    protected $table = 'password_resets';
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'token_hash', 'expires_at', 'used', 'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used' => 'integer',
        'created_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
