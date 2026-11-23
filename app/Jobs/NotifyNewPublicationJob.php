<?php

namespace App\Jobs;

use App\Models\Publication;
use App\Services\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifyNewPublicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $publicationId;
    public array $clientIds;
    public string $titre;
    public string $corps;

    /**
     * Crée un job de notification de nouvelle publication pour un lot de clients.
     *
     * @param  int  $publicationId  Identifiant de la publication
     * @param  array<int>  $clientIds  IDs des clients à notifier
     * @param  string  $titre  Titre de la publication
     * @param  string  $corps  Corps du message
     */
    public function __construct(int $publicationId, array $clientIds, string $titre, string $corps)
    {
        $this->publicationId = $publicationId;
        $this->clientIds = $clientIds;
        $this->titre = $titre;
        $this->corps = $corps;
    }

    /**
     * Exécute le job : envoie une notification push à chaque client du lot.
     *
     * @param  PushService  $push
     * @return void
     */
    public function handle(PushService $push): void
    {
        foreach ($this->clientIds as $clientId) {
            try {
                $push->notifyEvent(
                    (int) $clientId,
                    'nouvelle_publication',
                    $this->titre,
                    $this->corps,
                    '/publications/'.$this->publicationId
                );
            } catch (\Throwable $e) {
                // Un échec par destinataire ne doit pas faire échouer le lot entier.
                Log::warning('NotifyNewPublicationJob: échec pour le client '.$clientId.': '.$e->getMessage());
            }
        }

        Log::info('NotifyNewPublicationJob: publication '.$this->publicationId.' notifiée à '.count($this->clientIds).' clients.');
    }

    /**
     * Gère l'échec du job.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('NotifyNewPublicationJob: échec global pour la publication '.$this->publicationId.': '.$exception->getMessage());
    }
}
