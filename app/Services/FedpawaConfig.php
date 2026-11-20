<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Parité de fedpawa_env() : lecture robuste de configuration.
 *
 * Priorité :
 *  1. config('fedpawa.<NAME>') — overrides de test (Config::set dans les tests)
 *  2. variable d'environnement réelle (env()) — chargement du .env principal
 *  3. fichier campay_live.env sur le disque — identifiants Campay dédiés
 *  sinon défaut.
 */
class FedpawaConfig
{
    public static function get(string $name, string $default = ''): string
    {
        $v = (string) Config::get('fedpawa.'.$name, '');
        if ($v !== '') {
            return $v;
        }
        $env = env($name);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        // Fallback : lecture directe depuis campay_live.env sur le disque.
        // Ce fichier contient les identifiants Campay de production qui ne
        // doivent pas figurer dans le .env général. Lecture en lecture seule,
        // aucune valeur n'est jamais affichée ni journalisée.
        $envFile = base_path('campay_live.env');
        if (is_file($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    if (trim($key) === $name) {
                        return trim($value, '"\'');
                    }
                }
            }
        }

        return $default;
    }

    public static function bool(string $name, bool $default = true): bool
    {
        return self::get($name, $default ? '1' : '0') === '1';
    }
}
