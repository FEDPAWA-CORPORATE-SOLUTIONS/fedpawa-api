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
        $this->loadMailEnvIfNeeded();

        $this->host = env('IMAP_HOST', '');
        $this->port = (int) env('IMAP_PORT', 993);
    }

    /** Charge mail.env si les variables IMAP ne sont pas déjà dans l'environnement. */
    private function loadMailEnvIfNeeded(): void
    {
        if (!empty(env('IMAP_HOST'))) {
            return;
        }

        $mailEnvPath = base_path('mail.env');
        if (!file_exists($mailEnvPath)) {
            return;
        }

        $lines = file($mailEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Supprimer les guillemets éventuels autour de la valeur
            if (preg_match('/^["\'](.*)["\']$/', $value, $m)) {
                $value = $m[1];
            }
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
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
    public function listMessages(int $account = 1, int $limit = 50, string $mailbox = 'INBOX', int $page = 1): array
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

            // Trier par ID descendant (plus récent d'abord)
            rsort($messageIds);

            // Pagination : calculer l'offset et la tranche
            $perPage = $limit;
            $offset = ($page - 1) * $perPage;
            $pageIds = array_slice($messageIds, $offset, $perPage);

            if (empty($pageIds)) {
                return [];
            }

            $messages = [];

            foreach ($pageIds as $msgNo) {
                $fetchResponse = $this->sendCommand($socket, "FETCH {$msgNo} (BODY.PEEK[HEADER.FIELDS (SUBJECT FROM DATE)])");
                $parsed = $this->parseFetchResponse($fetchResponse);

                if ($parsed) {
                    $parsed['uid'] = $msgNo;
                    $messages[] = $parsed;
                }
            }

            return $messages;
        } finally {
            $this->disconnect($socket);
        }
    }

    /** Recherche des messages par sujet ou expéditeur via IMAP SEARCH */
    public function searchMessages(int $account = 1, string $query, int $limit = 50): array
    {
        $socket = $this->connect($account);
        if (!$socket) {
            return [];
        }

        try {
            $this->sendCommand($socket, "SELECT INBOX");

            // Recherche par sujet ou expéditeur
            $searchCmd = "SEARCH (SUBJECT \"{$query}\" OR FROM \"{$query}\")";
            $searchResponse = $this->sendCommand($socket, $searchCmd);
            $messageIds = $this->parseSearchResponse($searchResponse);

            if (empty($messageIds)) {
                return [];
            }

            rsort($messageIds);
            $messageIds = array_slice($messageIds, 0, $limit);

            $messages = [];
            foreach ($messageIds as $msgNo) {
                $fetchResponse = $this->sendCommand($socket, "FETCH {$msgNo} (BODY.PEEK[HEADER.FIELDS (SUBJECT FROM DATE)])");
                $parsed = $this->parseFetchResponse($fetchResponse);
                if ($parsed) {
                    $parsed['uid'] = $msgNo;
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

    /** Extrait le corps texte d'une réponse RFC822 complète. */
    private function extractTextBody(string $response): string
    {
        // Séparer les en-têtes du corps
        $body = $this->extractRfc822Body($response);
        if ($body === '') {
            return '';
        }

        // Déterminer le Content-Type principal
        $contentType = $this->extractHeaderField($response, 'Content-Type') ?? 'text/plain';
        $charset = $this->extractCharset($contentType);

        // Si c'est multipart, parser les parties
        if (stripos($contentType, 'multipart/') === 0) {
            $boundary = $this->extractBoundary($contentType);
            if ($boundary === null) {
                return $this->decodeBody($body, $charset);
            }
            $parts = $this->parseMultipart($body, $boundary);
            foreach ($parts as $part) {
                $partContentType = $part['headers']['content-type'] ?? 'text/plain';
                if (stripos($partContentType, 'text/plain') === 0) {
                    $partCharset = $this->extractCharset($partContentType);
                    return $this->decodeBody($part['body'], $partCharset);
                }
            }
            // Aucun text/plain trouvé, retourner le premier corps textuel disponible
            foreach ($parts as $part) {
                $partContentType = $part['headers']['content-type'] ?? '';
                if (stripos($partContentType, 'text/') === 0) {
                    $partCharset = $this->extractCharset($partContentType);
                    return $this->decodeBody($part['body'], $partCharset);
                }
            }
        }

        return $this->decodeBody($body, $charset);
    }

    /** Extrait le corps HTML d'une réponse RFC822 complète. */
    private function extractHtmlBody(string $response): string
    {
        $body = $this->extractRfc822Body($response);
        if ($body === '') {
            return '';
        }

        $contentType = $this->extractHeaderField($response, 'Content-Type') ?? 'text/plain';

        if (stripos($contentType, 'multipart/') === 0) {
            $boundary = $this->extractBoundary($contentType);
            if ($boundary === null) {
                return $this->decodeBody($body, $this->extractCharset($contentType));
            }
            $parts = $this->parseMultipart($body, $boundary);
            foreach ($parts as $part) {
                $partContentType = $part['headers']['content-type'] ?? 'text/plain';
                if (stripos($partContentType, 'text/html') === 0) {
                    $partCharset = $this->extractCharset($partContentType);
                    return $this->decodeBody($part['body'], $partCharset);
                }
            }
            // Fallback : premier text/ trouvé
            foreach ($parts as $part) {
                $partContentType = $part['headers']['content-type'] ?? '';
                if (stripos($partContentType, 'text/html') === 0) {
                    $partCharset = $this->extractCharset($partContentType);
                    return $this->decodeBody($part['body'], $partCharset);
                }
            }
        } elseif (stripos($contentType, 'text/html') === 0) {
            return $this->decodeBody($body, $this->extractCharset($contentType));
        }

        return '';
    }

    /** Extrait la liste des pièces jointes d'une réponse RFC822 complète. */
    private function extractAttachments(string $response): array
    {
        $body = $this->extractRfc822Body($response);
        if ($body === '') {
            return [];
        }

        $contentType = $this->extractHeaderField($response, 'Content-Type') ?? 'text/plain';

        if (stripos($contentType, 'multipart/') !== 0) {
            return [];
        }

        $boundary = $this->extractBoundary($contentType);
        if ($boundary === null) {
            return [];
        }

        $parts = $this->parseMultipart($body, $boundary);
        $attachments = [];

        foreach ($parts as $part) {
            $partHeaders = $part['headers'] ?? [];
            $partContentType = $partHeaders['content-type'] ?? '';
            $contentDisposition = $partHeaders['content-disposition'] ?? '';

            // Une pièce jointe a généralement Content-Disposition: attachment
            if (stripos($contentDisposition, 'attachment') !== 0) {
                continue;
            }

            $filename = $this->extractFilename($contentDisposition);
            if ($filename === null) {
                $filename = $this->extractHeaderField($partHeaders['content-type'] ?? '', 'name') ?? 'piece-jointe';
            }

            $partCharset = $this->extractCharset($partContentType);
            $decodedBody = $this->decodeBody($part['body'], $partCharset);

            $attachments[] = [
                'nom' => $filename,
                'type' => $this->extractMimeType($partContentType),
                'taille' => strlen($decodedBody),
                'contenu' => base64_encode($decodedBody), // Stocké en base64 pour le JSON
                'content_transfer_encoding' => $partHeaders['content-transfer-encoding'] ?? '7bit',
            ];
        }

        return $attachments;
    }

    // ============================================================
    // Helpers privés de parsing MIME/RFC822
    // ============================================================

    /** Sépare les en-têtes du corps dans une réponse RFC822. */
    private function extractRfc822Body(string $response): string
    {
        // La réponse IMAP FETCH RFC822 peut contenir des lignes de tag et des accolades {size}
        // Chercher le double CRLF qui sépare en-têtes et corps
        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            // Essayer avec simple \n\n
            $headerEnd = strpos($response, "\n\n");
            if ($headerEnd === false) {
                return '';
            }
            return substr($response, $headerEnd + 2);
        }
        return substr($response, $headerEnd + 4);
    }

    /** Extrait le charset depuis un en-tête Content-Type. */
    private function extractCharset(string $contentType): string
    {
        if (preg_match('/charset\s*=\s*["\']?([^\s";]+)/i', $contentType, $match)) {
            return strtoupper(trim($match[1], '"\''));
        }
        return 'UTF-8';
    }

    /** Extrait la boundary depuis un Content-Type multipart. */
    private function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary\s*=\s*["\']?([^\s";]+)/i', $contentType, $match)) {
            return trim($match[1], '"\'' );
        }
        return null;
    }

    /** Parse un corps multipart en parties individuelles. */
    private function parseMultipart(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $parts = [];
        $chunks = explode($delimiter, $body);

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || $chunk === '--') {
                continue;
            }

            // Séparer en-têtes de partie et corps
            $headerEnd = strpos($chunk, "\r\n\r\n");
            if ($headerEnd === false) {
                $headerEnd = strpos($chunk, "\n\n");
                if ($headerEnd === false) {
                    continue;
                }
                $partHeadersStr = '';
                $partBody = substr($chunk, $headerEnd + 2);
            } else {
                $partHeadersStr = substr($chunk, 0, $headerEnd);
                $partBody = substr($chunk, $headerEnd + 4);
            }

            // Nettoyer le corps (supprimer le CRLF final et les --boundary--)
            $partBody = rtrim($partBody, "\r\n");
            if (substr($partBody, -2) === '--') {
                $partBody = rtrim(substr($partBody, 0, -2), "\r\n");
            }

            $headers = [];
            foreach (explode("\r\n", $partHeadersStr) as $headerLine) {
                if (strpos($headerLine, ':') !== false) {
                    list($key, $value) = explode(':', $headerLine, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }
            }

            $parts[] = [
                'headers' => $headers,
                'body' => $partBody,
            ];
        }

        return $parts;
    }

    /** Décode le corps selon l'encodage Content-Transfer-Encoding. */
    private function decodeBody(string $body, string $charset): string
    {
        // Le corps brut du FETCH peut avoir un CRLF de fin
        $body = rtrim($body, "\r\n");

        // Détecter l'encodage de transfert depuis les en-têtes (passé via le contexte)
        // Par défaut, essayer base64 si le contenu ressemble à du base64
        if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $body) && strlen($body) > 0 && strlen($body) % 4 === 0) {
            $decoded = base64_decode($body, true);
            if ($decoded !== false) {
                $body = $decoded;
            }
        }

        // Convertir le charset si nécessaire
        if ($charset !== 'UTF-8' && $charset !== '') {
            $converted = iconv($charset, 'UTF-8//IGNORE', $body);
            if ($converted !== false) {
                $body = $converted;
            }
        }

        return $body;
    }

    /** Extrait le nom de fichier depuis Content-Disposition. */
    private function extractFilename(string $contentDisposition): ?string
    {
        if (preg_match('/filename\s*=\s*["\']?([^\s";]+)/i', $contentDisposition, $match)) {
            return trim($match[1], '"\'' );
        }
        if (preg_match('/filename\*\s*=\s*UTF-8\'\'([^\s;]+)/i', $contentDisposition, $match)) {
            return rawurldecode($match[1]);
        }
        return null;
    }

    /** Extrait le type MIME depuis un Content-Type. */
    private function extractMimeType(string $contentType): string
    {
        if (preg_match('/^([^\s;]+)/', $contentType, $match)) {
            return trim($match[1]);
        }
        return 'application/octet-stream';
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

    /** Marque un message comme lu sur le serveur IMAP (STORE \Seen). */
    public function markAsSeen(int $account, int $uid): bool
    {
        $socket = $this->connect($account);
        if (!$socket) {
            return false;
        }

        try {
            $this->sendCommand($socket, "SELECT INBOX");
            $response = $this->sendCommand($socket, "STORE {$uid} +FLAGS (\\Seen)");
            return stripos($response, 'OK') !== false;
        } finally {
            $this->disconnect($socket);
        }
    }

    /** Marque un message comme supprimé sur le serveur IMAP (STORE \Deleted + EXPUNGE). */
    public function markAsDeleted(int $account, int $uid): bool
    {
        $socket = $this->connect($account);
        if (!$socket) {
            return false;
        }

        try {
            $this->sendCommand($socket, "SELECT INBOX");
            $ok = stripos($this->sendCommand($socket, "STORE {$uid} +FLAGS (\\Deleted)"), 'OK') !== false;
            if ($ok) {
                $this->sendCommand($socket, "EXPUNGE");
            }
            return $ok;
        } finally {
            $this->disconnect($socket);
        }
    }
}
