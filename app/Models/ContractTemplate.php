<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractTemplate extends Model
{
    protected $table = 'contract_templates';
    public $timestamps = false;

    protected $fillable = [
        'name', 'content', 'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function history()
    {
        return $this->hasMany(ContractHistory::class, 'template_id');
    }
}
