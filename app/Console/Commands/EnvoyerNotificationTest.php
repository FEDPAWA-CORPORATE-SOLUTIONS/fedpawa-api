<?php

namespace App\Console\Commands;

use App\Services\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * notifications:envoyer-test {user} {type}
 *
 * Preuve serveur (côté B) : déclenche un VRAI envoi via PushService::notifyEvent()
 * pour un client (id ou e-mail) et un type du catalogue. Affiche le résultat brut :
 * event_id écrit, abonnements comptés, push_envoye, motif.
 *
 * Un échec push (endpoint factice injoignable) est géré sans erreur fatale.
 */
class EnvoyerNotificationTest extends Command
{
    protected $signature = 'notifications:envoyer-test
                            {user : id client ou e-mail}
                            {type : type du catalogue (voir liste ci-dessous)}
                            {--title= : titre de la notification}
                            {--body= : corps de la notification}
                            {--url= : url cible du clic}';

    protected $description = 'Envoi de test d\'une notification du catalogue via PushService (écrit notification_events + push si préférences actives)';

    public function handle(PushService $push): int
    {
        $user = (string) $this->argument('user');
        $type = (string) $this->argument('type');

        if (! in_array($type, PushService::EVENT_TYPES, true)) {
            $this->error("Type inconnu : {$type}");
            $this->line('Catalogue : '.implode(', ', PushService::EVENT_TYPES));

            return self::FAILURE;
        }

        $client = is_numeric($user)
            ? DB::table('clients')->where('id', (int) $user)->first()
            : DB::table('clients')->where('email', $user)->first();

        if (! $client) {
            $this->error("Client introuvable : {$user}");

            return self::FAILURE;
        }

        $clientId = (int) $client->id;
        $subs = DB::table('push_subscriptions')->where('client_id', $clientId)->count();
        $prefs = DB::table('notification_preferences')->where('user_id', $clientId)->pluck('enabled', 'type')->toArray();

        $this->info("Client #{$clientId} ({$client->email}) — souscriptions push : {$subs} — préférences enregistrées : ".json_encode($prefs, JSON_UNESCAPED_UNICODE));

        $title = (string) ($this->option('title') ?: 'Notification de test FEDPAWA');
        $body = (string) ($this->option('body') ?: 'Ceci est une notification de test envoyée depuis le serveur.');
        $url = $this->option('url') ?: '/espace-client';

        $result = $push->notifyEvent($clientId, $type, $title, $body, $url !== '' ? $url : null);

        $this->line('Résultat notifyEvent :');
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
