<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CONTRAT-PUBLICATIONS.md §1 — table publication_lectures.
 * Unicité (publication_id, client_id) : sert le compteur de non-lues
 * et rend le marquage « lu » idempotent.
 */
class PublicationLecture extends Model
{
    protected $table = 'publication_lectures';

    protected $fillable = ['publication_id', 'client_id', 'lu_le'];

    protected $casts = [
        'publication_id' => 'integer',
        'client_id' => 'integer',
        'lu_le' => 'datetime',
    ];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
