<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Parité de src/Service/OtpService.php (OTP de signature de contrat).
 * Stockage : cache (clé par scope, comme le fichier otp_<id>.txt côté PHP)
 * avec hash du code — jamais en clair. Pas d'expiration (parité : le
 * fichier source n'expire pas non plus ; il est purgé à la signature).
 */
class OtpService
{
    private const SCOPES = ['client', 'admin'];

    private static function key(int $id, string $scope): string
    {
        if (! in_array($scope, self::SCOPES, true)) {
            $scope = 'client';
        }
        $prefix = ($scope === 'admin') ? 'otp_admin_' : 'otp_';

        return $prefix.$id;
    }

    /** Signature historique conservée : generate(clientId) = scope client. */
    public static function generate(int $clientId, string $scope = 'client'): string
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::key($clientId, $scope), Hash::make($otp));

        return $otp;
    }

    public static function verify(int $clientId, string $otp, string $scope = 'client'): bool
    {
        $hash = Cache::get(self::key($clientId, $scope));
        if (! is_string($hash) || $hash === '') {
            return false;
        }

        return Hash::check($otp, $hash);
    }

    public static function clear(int $clientId, string $scope = 'client'): void
    {
        Cache::forget(self::key($clientId, $scope));
    }
}
