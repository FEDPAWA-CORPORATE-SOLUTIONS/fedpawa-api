<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'admins';
    public $timestamps = false;

    protected $fillable = [
        'email', 'mot_de_passe',
    ];

    protected $hidden = ['mot_de_passe'];
}
