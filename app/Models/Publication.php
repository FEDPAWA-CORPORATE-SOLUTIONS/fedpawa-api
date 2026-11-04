<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CONTRAT-PUBLICATIONS.md §1 — table publications.
 * États : brouillon → programme (publie_le futur) → publie → depublie.
 */
class Publication extends Model
{
    public const TYPES = ['texte', 'texte_image', 'texte_video', 'galerie'];

    public const STATUTS = ['brouillon', 'programme', 'publie', 'depublie'];

    protected $fillable = [
        'titre', 'chapeau', 'contenu', 'type', 'statut',
        'publie_le', 'auteur_id', 'vues',
    ];

    protected $casts = [
        'publie_le' => 'datetime',
        'auteur_id' => 'integer',
        'vues' => 'integer',
    ];

    public function medias(): HasMany
    {
        return $this->hasMany(PublicationMedia::class)->orderBy('ordre')->orderBy('id');
    }

    public function lectures(): HasMany
    {
        return $this->hasMany(PublicationLecture::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'auteur_id');
    }

    /** Scope « visible publiquement » : statut publie ET publie_le atteint. */
    public function scopePublie($query)
    {
        return $query->where('statut', 'publie')
            ->whereNotNull('publie_le')
            ->where('publie_le', '<=', now());
    }

    public function estPubliee(): bool
    {
        return $this->statut === 'publie'
            && $this->publie_le !== null
            && $this->publie_le->lte(now());
    }
}
