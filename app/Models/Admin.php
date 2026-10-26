<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    protected $table = 'admins';
    public $timestamps = false;

    protected $fillable = [
        'email', 'mot_de_passe',
    ];

    protected $hidden = ['mot_de_passe'];
}
