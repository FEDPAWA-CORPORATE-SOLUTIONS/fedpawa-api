<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PortantSignService
{
    private string $baseUrl;
    private string $apiKey;
    private int $maxRetries = 3;

    public function __construct()
    {
        $this->baseUrl = rtrim(Config::get('portant.base_url', env('PORTANT_BASE_URL', 'https://mcp.portant.co')), '/');
        $this->apiKey = Config::get('portant.api_key', env('PORTANT_API_KEY', ''));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Valide qu'une chaîne est du base64 valide.
     */
    private function isValidBase64(string $value): bool
    {
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
            return false;
        }
        $decoded = base64_decode($value, true);
        return $decoded !== false && base64_encode($decoded) === $value;
    }

    /**
     * Calcule le hash SHA-256 d'une chaîne base64 décodée.
     */
    private function hashBase64Content(string $base64): string
    {
        $decoded = base64_decode($base64, true);
        return $decoded !== false ? hash('sha256', $decoded) : '';
    }

    /**
     * Envoyer un document à signer via Portant.
     *
     * @param  array{
     *   title: string,
     *   pdf_base64: string,
     *   signer_name: string,
     *   signer_email: string,
     *   sender_name?: string,
     *   sender_email?: string,
     *   language?: string,
     *   sender_signs?: bool,
     * }  $data
     * @return array{success: bool, request_id?: string, request_token?: string, next_step?: string, error?: string, api_response?: array, document_hash?: string}
     */
    public function sendForSignature(array $data): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Portant API non configurée (PORTANT_API_KEY manquante)'];
        }

        // Validation du base64
        if (!$this->isValidBase64($data['pdf_base64'] ?? '')) {
            return ['success' => false, 'error' => 'Le document PDF n\'est pas un base64 valide.'];
        }

        $documentHash = $this->hashBase64Content($data['pdf_base64']);

        $payload = [
            'title' => $data['title'],
            'document' => [
                'pdf_base64' => $data['pdf_base64'],
            ],
            'signer' => [
                'name' => $data['signer_name'],
                'email' => $data['signer_email'],
            ],
            'sender_signs' => $data['sender_signs'] ?? true,
            'language' => $data['language'] ?? 'fr',
            'certificate' => true,
        ];

        // Ne pas passer sender_email/sender_name : Portant utilise le compte API (clé API) comme expéditeur.
        // Passer un email différent de celui du compte Portant provoque une erreur 400.

        $lastError = '';
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            $attempt++;

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ])->timeout(30)->post("{$this->baseUrl}/v1/requests", $payload);

                if ($response->failed()) {
                    $status = $response->status();
                    $body = $response->body();
                    Log::error('Portant API error (attempt '.$attempt.'): '.$status.' '.$body);

                    // Ne pas retry sur les erreurs 4xx (erreur client)
                    if ($status >= 400 && $status < 500) {
                        return [
                            'success' => false,
                            'error' => 'Erreur API Portant ('.$status.') : '.$body,
                            'document_hash' => $documentHash,
                        ];
                    }

                    $lastError = 'Erreur API Portant ('.$status.') : '.$body;

                    if ($attempt >= $this->maxRetries) {
                        return [
                            'success' => false,
                            'error' => $lastError,
                            'document_hash' => $documentHash,
                        ];
                    }

                    // Backoff exponentiel : 1s, 2s, 4s
                    usleep($attempt * 1000000);
                    continue;
                }

                $data = $response->json() ?? [];
                // La réponse peut être directement le résultat (Pasant) ou enveloppée dans "result"
                $result = $data['result'] ?? $data ?? null;

                if (!$result) {
                    return [
                        'success' => false,
                        'error' => 'Réponse Portant inattendue : '.json_encode($data),
                        'document_hash' => $documentHash,
                    ];
                }

                return [
                    'success' => true,
                    'request_id' => $result['request_id'] ?? null,
                    'request_token' => $result['request_token'] ?? null,
                    'next_step' => $result['next_step'] ?? null,
                    'api_response' => $data,
                    'document_hash' => $documentHash,
                ];
            } catch (\Exception $e) {
                Log::error('Portant exception (attempt '.$attempt.'): '.$e->getMessage());
                $lastError = 'Exception : '.$e->getMessage();

                if ($attempt >= $this->maxRetries) {
                    return [
                        'success' => false,
                        'error' => $lastError,
                        'document_hash' => $documentHash,
                    ];
                }

                usleep($attempt * 1000000);
            }
        }

        return [
            'success' => false,
            'error' => $lastError,
            'document_hash' => $documentHash,
        ];
    }

    /**
     * Vérifier le statut d'une demande.
     */
    public function getStatus(string $requestId): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->timeout(30)->get("{$this->baseUrl}/v1/requests/{$requestId}");

            if ($response->failed()) {
                return ['success' => false, 'error' => 'Erreur statut ('.$response->status().') : '.$response->body()];
            }

            $data = $response->json() ?? [];
            // La réponse peut être directement le résultat ou enveloppée dans "result"
            $result = $data['result'] ?? $data ?? null;

            if (!$result) {
                return ['success' => false, 'error' => 'Réponse Portant inattendue : '.json_encode($data)];
            }

            return [
                'success' => true,
                'status' => $result['status'] ?? 'unknown',
                'data' => $result,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Exception : '.$e->getMessage()];
        }
    }

    /**
     * Télécharger le PDF signé.
     */
    public function getSignedPdf(string $requestId, string $requestToken = ''): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
        ];
        if ($requestToken !== '') {
            $headers['X-Request-Token'] = $requestToken;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(60)
                ->get("{$this->baseUrl}/v1/requests/{$requestId}/signed-pdf");

            if ($response->failed()) {
                return ['success' => false, 'error' => 'Erreur téléchargement ('.$response->status().') : '.$response->body()];
            }

            return [
                'success' => true,
                'content' => $response->body(),
                'content_type' => $response->header('Content-Type') ?? 'application/pdf',
                'document_hash' => hash('sha256', $response->body()),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Exception : '.$e->getMessage()];
        }
    }
}
