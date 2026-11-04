<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Parité de src/Service/CampayService.php :
 * - mode demo|live piloté par CAMPAY_MODE (demo.campay.net / www.campay.net) ;
 * - token permanent (APP KEYS) prioritaire sur username/password (/api/token) ;
 * - collect() : POST /api/collect {amount:int-string, currency:XAF, from:msisdn,
 *   description, external_reference} ;
 * - checkStatus() : GET /api/transaction/{id} ;
 * - URL toujours avec slash final (évite les 301 Campay) ;
 * - log fichier campay.log conservé.
 */
class CampayService
{
    private string $baseUrl;

    private string $username;

    private string $password;

    private ?string $permanentToken;

    public function __construct()
    {
        $mode = FedpawaConfig::get('CAMPAY_MODE', 'demo');
        $this->baseUrl = ($mode === 'live')
            ? 'https://www.campay.net'
            : 'https://demo.campay.net';
        $this->username = (string) (FedpawaConfig::get('CAMPAY_APP_USERNAME', '') ?: FedpawaConfig::get('CAMPAY_USER', 'test_user'));
        $this->password = (string) (FedpawaConfig::get('CAMPAY_APP_SECRET', '') ?: FedpawaConfig::get('CAMPAY_PASSWORD', 'test_pass'));
        $tok = FedpawaConfig::get('CAMPAY_TOKEN', '');
        $this->permanentToken = $tok !== '' ? $tok : null;
    }

    /**
     * Campay réellement configuré ? (audit) : sans token permanent ni
     * identifiants explicites, tout appel API échoue (credentials par défaut
     * « test_user »). Permet aux contrôleurs de dégrader proprement au lieu
     * d'un 500 avec trace.
     */
    public function isConfigured(): bool
    {
        if ($this->permanentToken !== null) {
            return true;
        }

        // Identifiants explicites requis : les valeurs par défaut du service
        // ne sont que des placeholders de parité, jamais de vrais credentials.
        return $this->username !== '' && $this->username !== 'test_user'
            && $this->password !== '' && $this->password !== 'test_pass';
    }

    private function log(string $message): void
    {
        try {
            $dir = storage_path('logs');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir.'/campay.log', now()->format('[Y-m-d H:i:s] ').$message.PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            Log::warning('campay log write failed: '.$e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    public function getToken(): string
    {
        if ($this->permanentToken !== null) {
            return $this->permanentToken;
        }
        $result = $this->curl('/api/token', 'POST', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        return (string) ($result['token'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function curl(string $endpoint, string $method = 'GET', array $data = [], string $token = ''): array
    {
        // Ensure trailing slash for 301 redirects (parité source)
        $url = $this->baseUrl.$endpoint.'/';
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = 'Token '.$token;
        }
        if ($method === 'GET') {
            $response = Http::withHeaders($headers)->get($url);
        } else {
            $response = Http::withHeaders($headers)->post($url, $data);
        }
        $httpCode = $response->status();
        $result = $response->json() ?? [];
        if ($httpCode >= 400) {
            $detail = is_array($result) ? ($result['detail'] ?? '') : '';
            $this->log("Error [$httpCode] at $url: ".($detail !== '' ? $detail : $response->body()));
            throw new \Exception("Campay API Error [$httpCode]: ".($detail !== '' ? $detail : 'Unknown'));
        }

        return is_array($result) ? $result : [];
    }

    /** Initie une collecte Mobile Money. @return array<string,mixed> */
    public function collect(float $amount, string $phone, string $reference): array
    {
        $token = $this->getToken();
        $response = $this->curl('/api/collect', 'POST', [
            'amount' => (string) (int) round($amount),
            'currency' => 'XAF',
            'from' => $phone,
            'description' => 'Paiement '.$reference,
            'external_reference' => $reference,
        ], $token);
        $this->log('Collecte initiée: '.json_encode($response));

        return $response;
    }

    /** @return array<string,mixed> */
    public function checkStatus(string $transactionId): array
    {
        $token = $this->getToken();

        return $this->curl('/api/transaction/'.$transactionId, 'GET', [], $token);
    }

    /**
     * L023 : numéros marchands de réception (config env, jamais codés en dur).
     *
     * @return array<string,string>
     */
    public static function merchantNumbers(): array
    {
        return [
            'orange' => FedpawaConfig::get('CAMPAY_ORANGE', '237691704802'),
            'mtn' => FedpawaConfig::get('CAMPAY_MTN', '237650863195'),
        ];
    }
}
