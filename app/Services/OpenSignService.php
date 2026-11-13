<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Service d'intégration OpenSign (signature électronique de contrats PDF).
 *
 * Gère :
 *  - l'authentification auprès d'OpenSign (login du compte de service),
 *  - le stockage du session token en base (table opensign_sessions),
 *  - le renouvellement proactif (tous les 3 jours) et réactif (sur erreur 401),
 *  - les appels API OpenSign pour créer/suivre/télécharger des signatures.
 *
 * Le session token ne sort JAMAIS de ce service : il est utilisé uniquement
 * côté backend pour appeler l'API OpenSign.
 */
class OpenSignService
{
    private string $baseUrl;
    private string $appId;
    private string $serviceEmail;
    private string $servicePassword;

    public function __construct()
    {
        $this->baseUrl = rtrim(FedpawaConfig::get('OPENSIGN_BASE_URL', 'http://206.72.195.234:8080'), '/');
        $this->appId = FedpawaConfig::get('OPENSIGN_APP_ID', 'opensign');
        $this->serviceEmail = FedpawaConfig::get('OPENSIGN_SERVICE_EMAIL', '');
        $this->servicePassword = FedpawaConfig::get('OPENSIGN_SERVICE_PASSWORD', '');
    }

    public function isConfigured(): bool
    {
        return $this->serviceEmail !== '' && $this->servicePassword !== '';
    }

    /**
     * Se connecte à OpenSign et stocke le session token en base.
     *
     * @return array{session_token: string, expires_at: \Carbon\Carbon}
     * @throws \Exception
     */
    public function authenticate(): array
    {
        $response = Http::withHeaders([
            'X-Parse-Application-Id' => $this->appId,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/app/functions/loginuser", [
            'email' => $this->serviceEmail,
            'password' => $this->servicePassword,
        ]);

        if ($response->failed()) {
            throw new \Exception("OpenSign login HTTP error: {$response->status()} ".$response->body());
        }

        $data = $response->json() ?? [];

        if (isset($data['error'])) {
            throw new \Exception("OpenSign login error: ".$data['error']);
        }

        $sessionToken = $data['sessionToken'] ?? null;
        if (!$sessionToken) {
            throw new \Exception("No sessionToken returned by OpenSign login");
        }

        // Expiration : 3 jours (renouvellement proactif demandé)
        $expiresAt = now()->addDays(3);

        DB::table('opensign_sessions')->updateOrInsert(
            ['id' => 1],
            [
                'session_token' => $sessionToken,
                'expires_at' => $expiresAt,
                'renewed_at' => now(),
                'updated_at' => now(),
            ]
        );

        Log::info('OpenSign: session token renewed, expires at '.$expiresAt->toDateTimeString());

        return [
            'session_token' => $sessionToken,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Retourne un session token valide, en le renouvelant si nécessaire.
     *
     * @return string
     * @throws \Exception
     */
    public function getValidToken(): string
    {
        $session = DB::table('opensign_sessions')->where('id', 1)->first();

        if ($session && $session->session_token && $session->expires_at && now() < $session->expires_at) {
            return $session->session_token;
        }

        return $this->authenticate()['session_token'];
    }

    /**
     * Appel générique vers l'API OpenSign avec gestion du token et retry sur 401.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     * @throws \Exception
     */
    private function callApi(string $endpoint, string $method = 'GET', array $data = []): array
    {
        $token = $this->getValidToken();

        $headers = [
            'X-Parse-Application-Id' => $this->appId,
            'X-Parse-Session-Token' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $url = "{$this->baseUrl}/app{$endpoint}";

        $execute = function () use ($url, $method, $headers, $data) {
            if ($method === 'GET') {
                return Http::withHeaders($headers)->get($url, $data);
            }
            return Http::withHeaders($headers)->post($url, $data);
        };

        $response = $execute();

        // Renouvellement réactif sur 401
        if ($response->status() === 401) {
            Log::warning('OpenSign: token expired (401), renewing and retrying');
            $token = $this->authenticate()['session_token'];
            $headers['X-Parse-Session-Token'] = $token;

            if ($method === 'GET') {
                $response = Http::withHeaders($headers)->get($url, $data);
            } else {
                $response = Http::withHeaders($headers)->post($url, $data);
            }
        }

        if ($response->failed()) {
            $body = $response->body();
            Log::error("OpenSign API error [{$response->status()}] at {$url}: {$body}");
            throw new \Exception("OpenSign API Error [{$response->status()}]: ".$body);
        }

        return $response->json() ?? [];
    }

    /* ============================
     * MÉTHODES MÉTIERS OPEENSIGN
     * ============================
     *
     * NOTE : Les noms d'endpoints Cloud Functions ci-dessous correspondent
     * à ceux observés dans le code source OpenSign (main.js). Les paramètres
     * exacts à envoyer doivent être confirmés contre votre instance OpenSign.
     * Ajustez si nécessaire.
     */

    /**
     * Crée une enveloppe de signature pour un PDF.
     *
     * @param  array<string,mixed>  $payload  ex: ['documentId' => '...', 'signers' => [...], ...]
     * @return array<string,mixed>
     */
    public function createEnvelope(array $payload): array
    {
        return $this->callApi('/functions/createEnvelope', 'POST', $payload);
    }

    /**
     * Vérifie le statut d'une enveloppe de signature.
     *
     * @param  string  $envelopeId
     * @return array<string,mixed>
     */
    public function getEnvelopeStatus(string $envelopeId): array
    {
        return $this->callApi('/functions/getEnvelopeStatus', 'GET', [
            'envelopeId' => $envelopeId,
        ]);
    }

    /**
     * Télécharge le document signé (et son certificat de completion).
     *
     * @param  string  $documentId
     * @return array<string,mixed>
     */
    public function downloadSignedDocument(string $documentId): array
    {
        return $this->callApi('/functions/downloadDocument', 'GET', [
            'documentId' => $documentId,
        ]);
    }

    /**
     * Signe un PDF directement (signature propre du compte connecté).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function signPdf(array $payload): array
    {
        return $this->callApi('/functions/signPdf', 'POST', $payload);
    }

    /**
     * Gestion de signature (statut, historique, etc.)
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function manageSign(array $payload): array
    {
        return $this->callApi('/functions/managesign', 'POST', $payload);
    }

    /**
     * Récupère la signature d'un document.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function getSignature(array $payload): array
    {
        return $this->callApi('/functions/getSignature', 'POST', $payload);
    }

    /**
     * Récupère un document depuis OpenSign.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function getDocument(array $payload): array
    {
        return $this->callApi('/functions/getDocument', 'POST', $payload);
    }
}
