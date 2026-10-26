<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Parité de fedpawa_env() : lecture robuste de configuration.
 * Priorité : config('fedpawa.<NAME>') (permet les overrides de test)
 * puis variable d'environnement réelle, sinon défaut.
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

        return $default;
    }

    public static function bool(string $name, bool $default = true): bool
    {
        return self::get($name, $default ? '1' : '0') === '1';
    }
}
