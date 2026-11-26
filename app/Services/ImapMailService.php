<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Connexion IMAP pour la lecture des mails reçus depuis la boîte PrivateEmail.
 *
 * Variables d'environnement attendues :
 *   IMAP_HOST, IMAP_PORT
 *   IMAP_USERNAME1, IMAP_PASSWORD1  (compte 1)
 *   IMAP_USERNAME2, IMAP_PASSWORD2  (compte 2)
 */
class ImapMailService
{
    protected string $host;
    protected int $port;

    public function __construct()
    {
        $this->host = env('IMAP_HOST', '');
        $this->port = (int) env('IMAP_PORT', 993);
    }

    /**
     * Établit une connexion IMAP vers le compte spécifié (1 ou 2).
     *
     * @return resource|false Ressource IMAP ou false en cas d'échec
     */
    public function connect(int $account = 1)
    {
        $username = env("IMAP_USERNAME{$account}", '');
        $password = env("IMAP_PASSWORD{$account}", '');

        if (empty($this->host) || empty($username) || empty($password)) {
            Log::warning("ImapMailService: configuration IMAP manquante pour le compte {$account}");
            return false;
        }

        $mailbox = "{{$this->host}:{$this->port}/imap/ssl/novalidate-cert}}INBOX";

        $connection = imap_open($mailbox, $username, $password);

        if (!$connection) {
            $errors = imap_last_error();
            Log::warning("ImapMailService: échec de connexion IMAP compte {$account}: " . $errors);
            imap_errors(); // efface la pile d'erreurs
            return false;
        }

        Log::info("ImapMailService: connecté au compte {$account} sur {$this->host}");
        return $connection;
    }

    /**
     * Ferme proprement une connexion IMAP.
     */
    public function disconnect($connection): void
    {
        if ($connection) {
            imap_close($connection, CL_EXPUNGE);
        }
    }

    /**
     * Récupère la liste des messages dans la boîte de réception.
     *
     * @return array Tableau de messages avec uid, sujet, expediteur, date_recue, taille
     */
    public function listMessages(int $account = 1, int $limit = 50): array
    {
        $connection = $this->connect($account);
        if (!$connection) {
            return [];
        }

        try {
            $emails = imap_search($connection, 'ALL');

            if (empty($emails)) {
                return [];
            }

            // Trier par UID descendant (plus récent d'abord) et limiter
            rsort($emails);
            $emails = array_slice($emails, 0, $limit);

            $messages = [];

            foreach ($emails as $msgNo) {
                $header = imap_headerinfo($connection, $msgNo);
                $overview = imap_fetch_overview($connection, $msgNo, 0);

                $messages[] = [
                    'uid' => $msgNo,
                    'sujet' => $header->subject ?? '(sans sujet)',
                    'expediteur' => $header->fromaddress ?? '',
                    'date_recue' => $header->date ?? '',
                    'taille' => $overview[0]->size ?? 0,
                    'lu' => !str_contains((string) $header->Recent, 'Recent'),
                ];
            }

            return $messages;
        } finally {
            $this->disconnect($connection);
        }
    }

    /**
     * Vérifie si la connexion IMAP fonctionne pour le compte spécifié.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(int $account = 1): array
    {
        $username = env("IMAP_USERNAME{$account}", '');

        $connection = $this->connect($account);
        if (!$connection) {
            return [
                'success' => false,
                'message' => "Échec de la connexion IMAP compte {$account} ({$username})",
            ];
        }

        $this->disconnect($connection);

        return [
            'success' => true,
            'message' => "Connexion IMAP réussie au compte {$account} ({$username})",
        ];
    }
}
