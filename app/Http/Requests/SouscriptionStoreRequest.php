<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SouscriptionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Champs communs de base
            'email' => ['required', 'email'],
            'pack' => ['required', 'string', 'max:255'],
            'nom' => ['required', 'string', 'max:255'],
            'prix' => ['required', 'numeric', 'gte:0'],
            'entreprise' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:32'],
            'service' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:255'],
            'planLabel' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
            'options.*' => ['array'],
            'paymentMethod' => ['nullable', 'string', 'max:32'],
            'momo_phone' => ['nullable', 'string', 'max:32'],
            'momo_operator' => ['nullable', 'string', 'max:32'],
            'rccm' => ['nullable', 'string', 'max:255'],
            'niu' => ['nullable', 'string', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'representant' => ['nullable', 'string', 'max:255'],
            'qualite' => ['nullable', 'string', 'max:255'],
            'capital' => ['nullable', 'numeric', 'min:0'],

            // ==================== 1. CRÉATION D'ENTREPRISE ====================
            'denomination' => ['sometimes', 'required', 'string', 'min:3'],
            'forme_juridique' => ['sometimes', 'required', 'string', 'in:SARL,SA,SAS,Autre'],
            'forme_autre' => ['sometimes', 'required_if:forme_juridique,Autre', 'string'],
            'secteur' => ['sometimes', 'required', 'string'],
            'description' => ['sometimes', 'required', 'string', 'min:50'],
            'civilite' => ['sometimes', 'required', 'string', 'in:Monsieur,Madame'],
            'nom_complet' => ['sometimes', 'required', 'string', 'min:5'],
            'fonction' => ['sometimes', 'required', 'string'],
            'nationalite' => ['sometimes', 'required', 'string'],
            'num_piece' => ['sometimes', 'required', 'string', 'alpha_num', 'min:6'],
            'email_creation' => ['sometimes', 'required', 'email'],
            'telephone_creation' => ['sometimes', 'required', 'regex:/^\\+?[0-9\\s]{9,16}$/'],
            'adresse_creation' => ['sometimes', 'required', 'string', 'min:5'],
            'pays_creation' => ['sometimes', 'required', 'string'],
            'capital_creation' => ['sometimes', 'required', 'numeric', 'min:100000'],
            'associes' => ['sometimes', 'required', 'string', 'min:10'],
            'dirigeant' => ['sometimes', 'required', 'string'],

            // ==================== 2. PROTECTION OAPI ====================
            'nom_marque' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'type_depot' => ['sometimes', 'required', 'string', 'in:Marque,Nom commercial,Brevet,Dessin'],
            'classes_oapi' => ['sometimes', 'required', 'string', 'min:2'],
            'description_oapi' => ['sometimes', 'required', 'string', 'min:30'],
            'titulaire_type' => ['sometimes', 'required', 'string', 'in:Physique,Morale'],
            'nom_titulaire' => ['sometimes', 'required', 'string', 'min:3'],
            'adresse_titulaire' => ['sometimes', 'required', 'string', 'min:10'],
            'pays_titulaire' => ['sometimes', 'required', 'string'],

            // ==================== 3. DOMICILIATION ====================
            'statut_structure' => ['sometimes', 'required', 'string', 'in:creation,existante'],
            'rccm_dom' => ['sometimes', 'required_if:statut_structure,existante', 'regex:/^RC\/.*\/[0-9]{4}\/B\/[0-9]{3}$/'],
            'niu_dom' => ['sometimes', 'required_if:statut_structure,existante', 'regex:/^[MNO0-9]{10}$/i'],
            'date_immat' => ['sometimes', 'required_if:statut_structure,existante', 'date', 'before:today'],
            'pack_domiciliation' => ['sometimes', 'required', 'string', 'in:national-basic,national-pro,inter-pro'],
            'duree_engagement' => ['sometimes', 'required', 'in:6,12,24'],
            'gestion_courrier' => ['sometimes', 'required', 'string', 'in:scan,reexpeditions,retention'],

            // ==================== 4. FISCALITÉ ====================
            'regime_fiscal' => ['sometimes', 'required', 'string', 'in:liberatoire,simplifie,normal'],
            'centre_impots' => ['sometimes', 'required', 'string', 'min:3'],
            'frequence_declaration' => ['sometimes', 'required', 'string', 'in:mensuelle,trimestrielle,annuelle'],
            'type_service_fiscal' => ['sometimes', 'required', 'string', 'in:declaration_mensuelle,bilan_annuel,audit_fiscal'],
            'dernier_exercice' => ['sometimes', 'required', 'integer', 'between:2000,2026'],

            // ==================== 5. CONTRATS ====================
            'type_contrat' => ['sometimes', 'required', 'string', 'in:travail,prestation,bail,partenariat,nda'],
            'partie_a' => ['sometimes', 'required', 'string', 'min:3'],
            'partie_b' => ['sometimes', 'required', 'string', 'min:3'],
            'langue_contrat' => ['sometimes', 'required', 'string', 'in:fr,en,bilingue'],
            'objet_contrat' => ['sometimes', 'required', 'string', 'min:30'],
            'montant_enjeu' => ['sometimes', 'required', 'numeric', 'min:0'],
            'clause_arbitrage' => ['sometimes', 'required', 'boolean'],

            // ==================== 6. CRÉATION DYNAMIQUE (Abonnement FORM_FIELDS) ====================
            'forme_juridique' => ['sometimes', 'required', 'string', 'in:SARL,SA,SAS,Autre'],

            // ==================== 7. OAPI DYNAMIQUE ====================
            'typeActif' => ['sometimes', 'required', 'string', 'in:Marque,Logiciel,Procédé'],
            'nomMarque' => ['sometimes', 'required', 'string', 'min:2'],
            'classes' => ['sometimes', 'required', 'string', 'min:2'],

            // ==================== 8. FISCALITÉ DYNAMIQUE ====================
            'regime' => ['sometimes', 'required', 'string', 'in:liberatoire,simplifie,normal'],
            'centreImpots' => ['sometimes', 'required', 'string', 'min:3'],
            'acf' => ['sometimes', 'required', 'string', 'min:3'],
            'declarations' => ['sometimes', 'required', 'string', 'min:3'],

            // ==================== 9. CONTRATS DYNAMIQUE ====================
            'typeContrat' => ['sometimes', 'required', 'string', 'in:travail,prestation,bail,partenariat,nda'],
            'cocontractant' => ['sometimes', 'required', 'string', 'min:3'],
            'langue' => ['sometimes', 'required', 'string', 'in:fr,en'],

            // ==================== 10. RECouvrement DYNAMIQUE ====================
            'montant' => ['sometimes', 'required', 'numeric', 'min:0'],
            'nbDebiteurs' => ['sometimes', 'required', 'string', 'min:1'],
            'nature' => ['sometimes', 'required', 'string', 'min:3'],
            'historique' => ['sometimes', 'required', 'string', 'min:10'],
        ];
    }

    public function messages(): array
    {
        return [
            // Messages communs
            'email.required' => 'Votre adresse e-mail est obligatoire.',
            'pack.required' => 'Veuillez sélectionner un pack d'abonnement.',
            'nom.required' => 'Le nom du demandeur est obligatoire.',

            // 1. Création d'entreprise
            'denomination.required' => 'Indiquez la dénomination sociale.',
            'denomination.min' => 'Minimum 3 caractères.',
            'forme_juridique.required' => 'Forme juridique obligatoire.',
            'secteur.required' => 'Secteur d'activité obligatoire.',
            'description.required' => 'Description détaillée obligatoire (min 50 car.).',
            'nom_complet.required' => 'Nom du dirigeant obligatoire.',
            'num_piece.required' => 'Numéro pièce d'identité requis.',
            'capital_creation.required' => 'Capital minimum 100 000 FCFA.',
            'capital_creation.min' => 'Capital minimum 100 000 FCFA.',
            'associes.required' => 'Au moins un associé requis.',
            'dirigeant.required' => 'Dirigeant obligatoire.',

            // 2. OAPI
            'nom_marque.required' => 'Nom de la marque requis.',
            'nom_marque.min' => 'Minimum 2 caractères.',
            'type_depot.required' => 'Type de dépôt obligatoire.',
            'classes_oapi.required' => 'Classes OAPI obligatoires.',
            'description_oapi.required' => 'Description détaillée nécessaire (min 30 car.).',

            // 3. Domiciliation
            'statut_structure.required' => 'Statut de la structure obligatoire.',
            'rccm_dom.required_if' => 'RCCM obligatoire pour entreprise existante.',
            'niu_dom.required_if' => 'NIU obligatoire pour entreprise existante.',
            'pack_domiciliation.required' => 'Formule de domiciliation obligatoire.',
            'duree_engagement.required' => 'Durée d'engagement obligatoire.',

            // 4. Fiscalité
            'regime_fiscal.required' => 'Régime fiscal obligatoire.',
            'centre_impots.required' => 'Centre des impôts obligatoire.',
            'type_service_fiscal.required' => 'Prestation fiscale obligatoire.',

            // 5. Contrats
            'type_contrat.required' => 'Type de contrat obligatoire.',
            'partie_a.required' => 'Nom de votre structure obligatoire.',
            'partie_b.required' => 'Nom du cocontractant obligatoire.',
            'objet_contrat.required' => 'Objet du contrat obligatoire.',

            // 6. Création dynamique
            'forme_juridique.required' => 'Forme juridique obligatoire.',
            'forme_juridique.in' => 'Forme juridique invalide.',

            // 7. OAPI dynamique
            'typeActif.required' => 'Type d'actif obligatoire.',
            'typeActif.in' => 'Type d'actif invalide.',
            'nomMarque.required' => 'Nom de la marque obligatoire.',
            'nomMarque.min' => 'Nom de la marque minimum 2 caractères.',
            'classes.required' => 'Classes OAPI obligatoires.',

            // 8. Fiscalité dynamique
            'regime.required' => 'Régime fiscal obligatoire.',
            'centreImpots.required' => 'Centre des impôts obligatoire.',
            'acf.required' => 'ACF obligatoire.',
            'declarations.required' => 'Déclarations obligatoires.',

            // 9. Contrats dynamiques
            'typeContrat.required' => 'Type de contrat obligatoire.',
            'typeContrat.in' => 'Type de contrat invalide.',
            'cocontractant.required' => 'Nom du cocontractant obligatoire.',
            'langue.required' => 'Langue du contrat obligatoire.',
            'langue.in' => 'Langue invalide.',

            // 10. Recouvrement dynamiques
            'montant.required' => 'Montant obligatoire.',
            'montant.min' => 'Montant doit être positif.',
            'nbDebiteurs.required' => 'Nombre de débiteurs obligatoire.',
            'nbDebiteurs.min' => 'Au moins 1 débiteur requis.',
            'nature.required' => 'Nature de la créance obligatoire.',
            'historique.required' => 'Historique obligatoire.',
        ];
    }
}
