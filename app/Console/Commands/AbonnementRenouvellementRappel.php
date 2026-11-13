<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AbonnementRenouvellementRappel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'abonnements:renouvellement-rappel {--jours=7 : Nombre de jours avant échéance pour envoyer le rappel}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envoie un e-mail de rappel de renouvellement aux abonnements arrivant à échéance (gabarit 14).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $jours = (int) $this->option('jours');
        $dateLimite = now()->addDays($jours)->format('Y-m-d');

        $abonnements = DB::table('abonnements as a')
            ->join('clients as c', 'c.id', '=', 'a.client_id')
            ->where('a.statut', 'actif')
            ->where('a.date_fin', '<=', $dateLimite)
            ->where('a.date_fin', '>=', now()->format('Y-m-d'))
            ->whereNotNull('c.email_verified_at')
            ->get([
                'a.id', 'a.client_id', 'a.pack', 'a.prix', 'a.date_fin',
                'c.nom', 'c.email',
            ]);

        if ($abonnements->isEmpty()) {
            $this->info('Aucun abonnement arrivant à échéance dans les '.$jours.' prochains jours.');

            return self::SUCCESS;
        }

        $notifier = app(NotificationService::class);
        $envoyes = 0;
        $sautes = 0;

        foreach ($abonnements as $sub) {
            $client = Client::find((int) $sub->client_id);
            if (! $client) {
                continue;
            }

            // Le gate e-mail vérifié est déjà appliqué dans sendEventEmail,
            // mais on saute explicitement ici pour éviter d'inutiles tentatives.
            if (! $client->estVerifie()) {
                $sautes++;
                continue;
            }

            $dateRenouvellement = now()->parse((string) $sub->date_fin)->format('d/m/Y');

            $result = $notifier->sendEventEmail(
                $client,
                'abonnement',
                'Votre abonnement « '.$sub->pack.' » arrive à échéance le '.$dateRenouvellement.' — FEDPAWA',
                'emails.14-abonnement-renouvellement',
                [
                    'client_nom' => $client->nom,
                    'pack' => $sub->pack,
                    'montant' => number_format((float) $sub->prix, 0, ',', ' '),
                    'devise' => 'XAF',
                    'date_renouvellement' => $dateRenouvellement,
                    'lien_action' => config('app.url', 'https://app.fedpawa.com').'/espace-client?tab=abonnements',
                ]
            );

            if ($result) {
                $envoyes++;
            } else {
                $sautes++;
            }
        }

        $this->info("Rappels de renouvellement : {$envoyes} envoyés, {$sautes} ignorés (SMTP non configuré ou e-mail non vérifié).");

        return self::SUCCESS;
    }
}
