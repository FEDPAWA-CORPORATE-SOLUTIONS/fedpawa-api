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
    ];

    protected $hidden = ['mot_de_passe'];

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
