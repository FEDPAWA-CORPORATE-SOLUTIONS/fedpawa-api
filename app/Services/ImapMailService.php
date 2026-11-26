<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Client IMAP minimal basé sur des sockets PHP brutes (SSL/TLS).
 *
 * Nécessite uniquement les fonctions stream_socket_* de PHP (standard, aucune extension additionnelle).
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
     * Ouvre une connexion IMAP SSL vers le compte spécifié (1 ou 2).
     *
     * @return resource|false Flux de socket ou false en cas d'échec
     */
    public function connect(int $account = 1)
    {
        $username = env("IMAP_USERNAME{$account}", '');
        $password = env("IMAP_PASSWORD{$account}", '');

        if (empty($this->host) || empty($username) || empty($password)) {
            Log::warning("ImapMailService: configuration IMAP manquante pour le compte {$account}");
            return false;
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $address = "ssl://{$this->host}:{$this->port}";
        $timeout = 30;
        $errno = null;
        $errstr = null;

        $socket = stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            Log::warning("ImapMailService: impossible de se connecter à {$address}: {$errstr} (errno {$errno})");
            return false;
        }

        stream_set_timeout($socket, $timeout);

        // Lire la bannière de bienvenue du serveur IMAP
        $banner = $this->readLine($socket);
        if ($banner === false) {
            fclose($socket);
            Log::warning("ImapMailService: pas de bannière IMAP reçue depuis {$this->host}");
            return false;
        }

        // Vérifier que le serveur supporte IMAP4rev1
        if (!preg_match('/\* OK.*IMAP4rev1/i', $banner)) {
            Log::warning("ImapMailService: serveur non-IMAP détecté sur {$this->host}: " . trim($banner));
            fclose($socket);
            return false;
        }

        // Authentification
        if (!$this->sendCommand($socket, "LOGIN {$username} {$password}")) {
            fclose($socket);
            Log::warning("ImapMailService: échec LOGIN pour le compte {$account}");
            return false;
        }

        Log::info("ImapMailService: connecté et authentifié au compte {$account} sur {$this->host}");
        return $socket;
    }

    /**
     * Ferme proprement la connexion IMAP.
     */
    public function disconnect($socket): void
    {
        if ($socket) {
            $this->sendCommand($socket, "LOGOUT");
            fclose($socket);
        }
    }

    /**
     * Récupère la liste des messages dans la boîte de réception.
     *
     * @return array Tableau de messages avec uid, sujet, expediteur, date_recue, taille, lu
     */
    public function listMessages(int $account = 1, int $limit = 50, string $mailbox = 'INBOX'): array
    {
        $socket = $this->connect($account);
        if (!$socket) {
            return [];
        }

        try {
            // Sélectionner la boîte de réception
            $this->sendCommand($socket, "SELECT {$mailbox}");

            // Rechercher tous les messages
            $searchResponse = $this->sendCommand($socket, "SEARCH ALL");
            $messageIds = $this->parseSearchResponse($searchResponse);

            if (empty($messageIds)) {
                return [];
            }

            // Trier par ID descendant (plus récent d'abord) et limiter
            rsort($messageIds);
            $messageIds = array_slice($messageIds, 0, $limit);

            $messages = [];

            foreach ($messageIds as $msgNo) {
                $fetchResponse = $this->sendCommand($socket, "FETCH {$msgNo} (BODY.PEEK[HEADER.FIELDS (SUBJECT FROM DATE)])");
                $parsed = $this->parseFetchResponse($fetchResponse);

                if ($parsed) {
                    $messages[] = $parsed;
                }
            }

            return $messages;
        } finally {
            $this->disconnect($socket);
        }
    }

    /** Récupère le contenu complet d'un message (corps + pièces jointes) */
    public function fetchFullMessage(int $account, int $uid): array
    {
        $socket = $this->connect($account);
        if (!$socket) {
            return [];
        }

        try {
            $this->sendCommand($socket, "SELECT INBOX");
            $fetchResponse = $this->sendCommand($socket, "FETCH {$uid} (RFC822)");

            return [
                'message_id' => $this->extractHeaderField($fetchResponse, 'Message-ID'),
                'expediteur' => $this->extractHeaderField($fetchResponse, 'From'),
                'destinataire' => $this->extractHeaderField($fetchResponse, 'To'),
                'sujet' => $this->extractHeaderField($fetchResponse, 'Subject'),
                'date_recue' => $this->extractHeaderField($fetchResponse, 'Date'),
                'corps_texte' => $this->extractTextBody($fetchResponse),
                'corps_html' => $this->extractHtmlBody($fetchResponse),
                'pieces_jointes' => $this->extractAttachments($fetchResponse),
            ];
        } finally {
            $this->disconnect($socket);
        }
    }

    /** Extrait le corps texte d'une réponse RFC822 (stub) */
    private function extractTextBody(string $response): string
    {
        // TODO: parser le corps texte depuis le contenu RFC822 complet
        return '';
    }

    /** Extrait le corps HTML d'une réponse RFC822 (stub) */
    private function extractHtmlBody(string $response): string
    {
        // TODO: parser le corps HTML depuis le contenu RFC822 complet
        return '';
    }

    /** Extrait la liste des pièces jointes d'une réponse RFC822 (stub) */
    private function extractAttachments(string $response): array
    {
        // TODO: parser les pièces jointes depuis le contenu RFC822 complet
        return [];
    }

    /**
     * Vérifie si la connexion IMAP fonctionne pour le compte spécifié.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(int $account = 1): array
    {
        $username = env("IMAP_USERNAME{$account}", '');

        $socket = $this->connect($account);
        if (!$socket) {
            return [
                'success' => false,
                'message' => "Échec de la connexion IMAP compte {$account} ({$username})",
            ];
        }

        $this->disconnect($socket);

        return [
            'success' => true,
            'message' => "Connexion IMAP réussie au compte {$account} ({$username})",
        ];
    }

    // ============================================================
    // Méthodes privées de bas niveau
    // ============================================================

    /**
     * Envoie une commande IMAP et lit la réponse jusqu'à la ligne de statut finale.
     *
     * @return string Réponse brute du serveur
     */
    private function sendCommand($socket, string $command): string
    {
        $tag = 'A' . uniqid('', true);
        $fullCommand = "{$tag} {$command}\r\n";
        fwrite($socket, $fullCommand);

        $response = '';
        $tagged = false;

        while (!feof($socket)) {
            $line = $this->readLine($socket);
            if ($line === false) {
                break;
            }

            $response .= $line . "\r\n";

            // Une ligne de statut finale commence par le tag
            if (preg_match("/^{$tag} (OK|NO|BAD|PREAUTH|BYE)/i", $line)) {
                $tagged = true;
                break;
            }
        }

        return $response;
    }

    /**
     * Lit une ligne depuis le socket IMAP (supprime le \r\n final).
     *
     * @return string|false
     */
    private function readLine($socket): string|false
    {
        $line = fgets($socket, 4096);
        if ($line === false) {
            return false;
        }
        return rtrim($line, "\r\n");
    }

    /**
     * Analyse la réponse SEARCH pour extraire les numéros de messages.
     */
    private function parseSearchResponse(string $response): array
    {
        // Le serveur répond : * SEARCH 1 2 3 4 5
        // ou : * SEARCH (réponse vide)
        if (!preg_match_all('/\* SEARCH\s+((?:\d+\s+)*)/', $response, $matches)) {
            return [];
        }

        $ids = [];
        foreach ($matches[1] as $group) {
            preg_match_all('/\d+/', $group, $numbers);
            $ids = array_merge($ids, $numbers[0]);
        }

        return array_map('intval', $ids);
    }

    /**
     * Analyse la réponse FETCH pour extraire sujet, expéditeur et date.
     */
    private function parseFetchResponse(string $response): ?array
    {
        // Rechercher le sujet
        $sujet = $this->extractHeaderField($response, 'Subject');
        $expediteur = $this->extractHeaderField($response, 'From');
        $date = $this->extractHeaderField($response, 'Date');

        if ($sujet === null && $expediteur === null && $date === null) {
            return null;
        }

        return [
            'sujet' => $sujet ?? '(sans sujet)',
            'expediteur' => $expediteur ?? '',
            'date_recue' => $date ?? '',
            'taille' => strlen($response),
            'lu' => true, // BODY.PEEK ne marque pas comme lu
        ];
    }

    /**
     * Extrait la valeur d'un champ d'en-tête depuis une réponse FETCH.
     */
    private function extractHeaderField(string $response, string $field): ?string
    {
        // Le corps des en-têtes est entre accolades : {taille}\r\ncontenu\r\n
        // Ou directement après BODY[] header
        $pattern = '/' . preg_quote($field, '/') . ':\s*(.+?)(?:\r\n|\r|\n)/i';
        if (preg_match($pattern, $response, $match)) {
            $value = trim($match[1]);
            // Décoder l'encodage MIME simple (ex: =?UTF-8?Q?...?=)
            return $this->decodeMimeHeader($value);
        }
        return null;
    }

    /**
     * Décode un en-tête MIME encodé (encodage Q ou B).
     */
    private function decodeMimeHeader(string $value): string
    {
        return iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?? $value;
    }
}
