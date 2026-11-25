<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catégorie d'utilisateurs pour le filtrage des mails admin.
 * Permet de créer des onglets personnalisés dans l'interface de boîte mail.
 */
class UserCategory extends Model
{
    protected $table = 'user_categories';

    protected $fillable = [
        'nom', 'couleur', 'est_systeme',
    ];

    protected $casts = [
        'est_systeme' => 'boolean',
    ];

    /**
     * Les catégories par défaut sont en lecture seule (impossible de les supprimer).
     */
    public function estSupprimable(): bool
    {
        return ! $this->est_systeme;
    }
}
