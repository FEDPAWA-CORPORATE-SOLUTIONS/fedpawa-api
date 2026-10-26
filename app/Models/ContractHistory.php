<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractHistory extends Model
{
    protected $table = 'contract_history';
    public $timestamps = false;

    protected $fillable = [
        'template_id', 'old_content', 'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function template()
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }
}
