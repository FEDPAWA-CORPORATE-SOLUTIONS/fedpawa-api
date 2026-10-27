<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * push:generate-vapid — génère une paire de clés VAPID (ES256 / P-256, RFC 8292)
 * et l'écrit dans .env si absente. Clés JAMAIS commitées (.env est ignoré).
 *
 * Encodage base64url sans padding (parité web-push libs).
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:generate-vapid {--force : Écraser des clés existantes}';

    protected $description = 'Génère VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY (ES256 P-256) dans .env si absents';

    public function handle(): int
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->error('.env introuvable — copiez .env.example d\'abord.');

            return self::FAILURE;
        }

        $env = (string) file_get_contents($envPath);
        $hasPublic = str_contains($env, 'VAPID_PUBLIC_KEY=');
        $hasPrivate = str_contains($env, 'VAPID_PRIVATE_KEY=');
        $hasPlaceholder = str_contains($env, 'VAPID_PUBLIC_KEY=PLACEHOLDER')
            || str_contains($env, 'VAPID_PRIVATE_KEY=PLACEHOLDER');

        if (($hasPublic && $hasPrivate) && ! $hasPlaceholder && ! $this->option('force')) {
            $this->info('Clés VAPID déjà présentes dans .env — rien à faire (utilisez --force pour régénérer).');

            return self::SUCCESS;
        }

        [$publicKey, $privateKey] = $this->generateKeys();

        $env = $this->setEnvValue($env, 'VAPID_PUBLIC_KEY', $publicKey);
        $env = $this->setEnvValue($env, 'VAPID_PRIVATE_KEY', $privateKey);
        file_put_contents($envPath, $env);

        $this->info('Clés VAPID générées et écrites dans .env :');
        $this->line("  VAPID_PUBLIC_KEY={$publicKey}");
        $this->line('  VAPID_PRIVATE_KEY='.str_repeat('*', strlen($privateKey) - 4).substr($privateKey, -4).' (masquée)');
        $this->warn('Ne commitez JAMAIS .env — les placeholders vivent dans .env.example.');

        return self::SUCCESS;
    }

    /**
     * P-256 + ES256 via openssl ; sortie base64url sans padding.
     *
     * @return array{0:string,1:string} [publicKey, privateKey]
     */
    private function generateKeys(): array
    {
        $config = [
            'curve_name' => 'prime256v1', // P-256 (secp256r1)
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];
        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new \RuntimeException('openssl_pkey_new(P-256) a échoué : '.openssl_error_string());
        }

        $details = openssl_pkey_get_details($res);
        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new \RuntimeException('Détails EC illisibles : '.openssl_error_string());
        }

        // Clé publique = x || y (65 octets avec le préfixe 0x04 ; les libs
        // web-push attendent les 64 octets bruts x||y encodés base64url).
        $publicKey = $this->b64url($details['ec']['x'].$details['ec']['y']);
        $privateKey = $this->b64url($details['ec']['d']);

        return [$publicKey, $privateKey];
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Remplace ou ajoute KEY=... (placeholder inclus) dans le contenu .env. */
    private function setEnvValue(string $env, string $key, string $value): string
    {
        $pattern = '/^'.$key.'=.*$/m';
        if (preg_match($pattern, $env)) {
            return (string) preg_replace($pattern, $key.'='.$value, $env, 1);
        }

        return rtrim($env, "\n")."\n\n{$key}={$value}\n";
    }
}
