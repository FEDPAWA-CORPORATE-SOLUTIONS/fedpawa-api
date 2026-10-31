<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $table = 'clients';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'civilité', 'nom', 'entreprise', 'forme_juridique', 'rc', 'ifu',
        'email', 'telephone', 'adresse', 'pays', 'activite', 'mot_de_passe',
        'date_inscription', 'statut', 'role',
        'email_verified_at', 'email_verification_hash', 'email_verification_sent_at',
    ];

    protected $hidden = ['mot_de_passe', 'email_verification_hash'];

    protected $casts = [
        'date_inscription' => 'datetime',
        'email_verified_at' => 'datetime',
        'email_verification_sent_at' => 'datetime',
    ];

    /** TTL d'un lien de vérification (CONTRAT-VERIF.md) : 72 heures. */
    public const VERIFICATION_TTL_HEURES = 72;

    /** E-mail vérifié ? (grand-fathering : les anciens clients ont email_verified_at renseigné). */
    public function estVerifie(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Génère un token de vérification (64 hex aléatoires), stocke UNIQUEMENT
     * son hash sha256 en base, renseigne email_verification_sent_at, et
     * retourne le token EN CLAIR une seule fois (à mettre dans l'URL de l'e-mail).
     */
    public function genererTokenVerification(): string
    {
        $token = bin2hex(random_bytes(32)); // 64 caractères hexadécimaux
        $this->email_verification_hash = hash('sha256', $token);
        $this->email_verification_sent_at = now();
        $this->save();

        return $token;
    }

    /** Marque le client vérifié et CONSOMME le token (usage unique). */
    public function marquerVerifie(): void
    {
        $this->email_verified_at = now();
        $this->email_verification_hash = null;
        $this->save();
    }

    /** Retrouve le client porteur d'un token (clair) — comparaison par hash sha256. */
    public static function trouverParTokenVerification(string $token): ?self
    {
        return self::where('email_verification_hash', hash('sha256', $token))->first();
    }

    /** Le lien de vérification est-il expiré (TTL 72 h depuis l'envoi) ? */
    public function tokenVerificationExpire(): bool
    {
        if ($this->email_verification_sent_at === null) {
            return true;
        }

        return $this->email_verification_sent_at->copy()->addHours(self::VERIFICATION_TTL_HEURES)->isPast();
    }

    public function abonnements()
    {
        return $this->hasMany(Abonnement::class);
    }

    public function paiements()
    {
        return $this->hasMany(Paiement::class);
    }

    public function ticketsSupport()
    {
        return $this->hasMany(TicketSupport::class);
    }

    public function factures()
    {
        return $this->hasMany(Facture::class);
    }

    public function contrats()
    {
        return $this->hasMany(Contrat::class);
    }

    public function courriers()
    {
        return $this->hasMany(Courrier::class);
    }

    public function kyc()
    {
        return $this->hasOne(Kyc::class);
    }

    public function passwordResets()
    {
        return $this->hasMany(PasswordReset::class);
    }
}
