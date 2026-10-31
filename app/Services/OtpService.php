<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * OTP de signature / réinitialisation.
 *
 * Stockage : cache (clé par scope) avec hash du code — JAMAIS en clair.
 * Le code n'est jamais écrit dans les logs (interdit depuis la campagne
 * signature : aucun Log::info contenant le code).
 *
 * TTL : 10 minutes (600 s) par défaut — un code expiré ne vérifie plus
 * (la clé de cache disparaît à l'expiration). Le code est purgé après
 * usage (clear).
 */
class OtpService
{
    private const SCOPES = ['client', 'admin', 'signature'];

    /** TTL par défaut d'un code OTP, en secondes (10 minutes). */
    public const TTL_SECONDES = 600;

    private static function key(int $id, string $scope): string
    {
        if (! in_array($scope, self::SCOPES, true)) {
            $scope = 'client';
        }
        $prefix = match ($scope) {
            'admin' => 'otp_admin_',
            'signature' => 'otp_signature_',
            default => 'otp_',
        };

        return $prefix.$id;
    }

    /**
     * Génère un code à 6 chiffres, le stocke haché avec un TTL.
     *
     * @param  int  $clientId  identifiant client (scope client) ou admin
     * @param  string  $scope  'client' | 'admin' | 'signature' (dossier de signature électronique)
     * @param  int  $ttlSecondes  durée de validité (défaut 600 s = 10 min)
     */
    public static function generate(int $clientId, string $scope = 'client', int $ttlSecondes = self::TTL_SECONDES): string
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::key($clientId, $scope), Hash::make($otp), $ttlSecondes);

        return $otp;
    }

    public static function verify(int $clientId, string $otp, string $scope = 'client'): bool
    {
        $hash = Cache::get(self::key($clientId, $scope));
        if (! is_string($hash) || $hash === '') {
            // absent ou expiré (TTL écoulé) => faux
            return false;
        }

        return Hash::check($otp, $hash);
    }

    public static function clear(int $clientId, string $scope = 'client'): void
    {
        Cache::forget(self::key($clientId, $scope));
    }
}
