<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Service/SubscriptionService.php :
 * - validation email/pack/nom/prix (mêmes messages d'erreur) ;
 * - réutilise le client existant par email, sinon le crée
 *   (mot de passe aléatoire hashé) ;
 * - persiste les détails du formulaire (rccm, niu, siege, representant,
 *   qualite, capital, entreprise, contact) en JSON dans abonnements.details ;
 * - statut initial 'en_attente'.
 *
 * NOTE (périmètre) : la colonne abonnements.details est une migration
 * incrémentale du schéma source (MigrationRunner) — la migration Laravel
 * reste à créer par le worker DB. En attendant, chaque test Feature crée
 * la colonne si absente ; ce service dégrade gracieusement si absente.
 */
class SubscriptionService
{
    /** @param  array<string,mixed>  $data @return array<string,mixed> */
    public function store(array $data): array
    {
        if (empty($data['email']) || empty($data['pack']) || empty($data['nom'])) {
            throw new \Exception('Données de souscription incomplètes.');
        }
        if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Adresse e-mail invalide.');
        }
        if (! isset($data['prix']) || ! is_numeric($data['prix']) || (float) $data['prix'] <= 0) {
            throw new \Exception('Prix invalide.');
        }

        return DB::transaction(function () use ($data) {
            // Réutiliser le client existant s'il existe déjà, sinon le créer
            $existing = DB::table('clients')->where('email', $data['email'])->first();
            if ($existing) {
                $clientId = (int) $existing->id;
            } else {
                $clientId = (int) DB::table('clients')->insertGetId([
                    'nom' => htmlspecialchars((string) $data['nom']),
                    'entreprise' => htmlspecialchars((string) ($data['entreprise'] ?? '')),
                    'email' => $data['email'],
                    'mot_de_passe' => \Hash::make(bin2hex(random_bytes(16))),
                    'date_inscription' => now()->format('Y-m-d H:i:s'),
                    'statut' => 'actif',
                    'role' => 'client',
                ]);
            }

            // L022: persister les pièces du formulaire pour le contrat
            $detailsData = [];
            foreach ([
                'rccm' => 'rccm', 'niu' => 'niu', 'adresse' => 'siege',
                'representant' => 'representant', 'fonction' => 'qualite',
            ] as $in => $key) {
                $v = trim((string) ($data[$in] ?? ''));
                if ($v !== '') {
                    $detailsData[$key] = htmlspecialchars($v);
                }
            }
            if (! empty($data['entreprise'])) {
                $detailsData['entreprise'] = htmlspecialchars(trim((string) $data['entreprise']));
            }
            if (! empty($data['nom'])) {
                $detailsData['contact'] = htmlspecialchars(trim((string) $data['nom']));
            }
            $detailsJson = $detailsData
                ? json_encode($detailsData, JSON_UNESCAPED_UNICODE)
                : null;

            $subscriptionId = (int) DB::table('abonnements')->insertGetId([
                'client_id' => $clientId,
                'pack' => htmlspecialchars((string) $data['pack']),
                'prix' => (float) $data['prix'],
                'date_debut' => now()->format('Y-m-d H:i:s'),
                'statut' => 'en_attente',
                'details' => $detailsJson,
            ]);

            return ['status' => 'success', 'client_id' => $clientId, 'subscription_id' => $subscriptionId];
        });
    }

    /** TODO(migration-worker) : colonne abonnements.details TEXT NULL à créer
     * dans database/migrations (schéma source : MigrationRunner.php). */
}
