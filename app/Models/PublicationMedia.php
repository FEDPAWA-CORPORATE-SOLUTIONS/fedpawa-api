<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * CONTRAT-PUBLICATIONS.md §1 — table publication_medias.
 * Fichier stocké sur le disque « public », servi via /storage/... (storage:link).
 */
class PublicationMedia extends Model
{
    protected $table = 'publication_medias';

    protected $fillable = [
        'publication_id', 'type', 'chemin', 'nom_origine', 'mime', 'taille_octets', 'ordre',
    ];

    protected $casts = [
        'publication_id' => 'integer',
        'taille_octets' => 'integer',
        'ordre' => 'integer',
    ];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /** URL publique du média (disque public). */
    public function url(): string
    {
        return Storage::disk('public')->url($this->chemin);
    }
}
