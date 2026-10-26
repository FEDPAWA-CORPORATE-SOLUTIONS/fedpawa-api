<?php

namespace App\Services;

use App\Models\LoginThrottle;

/**
 * Parité LoginThrottler PHP source — mêmes constantes, mêmes sémantiques.
 * Table login_throttle (créée par BE-SCHEMA, identique au schéma source) :
 * MAX_ATTEMPTS = 5 échecs → verrou 30 minutes, par (identifier, user_type).
 */
class LoginThrottleService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 30;

    /** @return int secondes restantes de verrou (0 si non verrouillé) */
    public static function getRemainingLock(string $identifier, string $type): int
    {
        $row = LoginThrottle::where('identifier', $identifier)
            ->where('user_type', $type)
            ->first();

        if (! $row || empty($row->locked_until)) {
            return 0;
        }

        return max(0, $row->locked_until->getTimestamp() - time());
    }

    /**
     * Enregistre un échec. Retourne true si CE failure pose le verrou
     * (5e échec) — même contrat que LoginThrottler::recordFailure.
     */
    public static function recordFailure(string $identifier, string $type, ?string $ip = null): bool
    {
        // Purge des entrées inactives depuis plus de 24h (parité PHP)
        LoginThrottle::where('last_attempt_at', '<', now()->subDay())->delete();

        $row = LoginThrottle::where('identifier', $identifier)
            ->where('user_type', $type)
            ->first();

        $attempts = ($row ? (int) $row->attempts : 0) + 1;
        $justLocked = false;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $lockedUntil = now()->addMinutes(self::LOCK_MINUTES);
            $justLocked = true;
        }

        if ($row) {
            // COALESCE(?, locked_until) : ne jamais réduire un verrou existant
            $row->attempts = $attempts;
            $row->ip = $ip;
            if ($lockedUntil ?? null) {
                $row->locked_until = $lockedUntil;
            }
            $row->last_attempt_at = now();
            $row->save();
        } else {
            LoginThrottle::create([
                'identifier' => $identifier,
                'user_type' => $type,
                'ip' => $ip,
                'attempts' => $attempts,
                'locked_until' => $lockedUntil ?? null,
            ]);
        }

        return $justLocked;
    }

    public static function recordSuccess(string $identifier, string $type): void
    {
        LoginThrottle::where('identifier', $identifier)
            ->where('user_type', $type)
            ->update(['attempts' => 0, 'locked_until' => null]);
    }

    public static function clearLock(string $identifier, string $type): bool
    {
        return LoginThrottle::where('identifier', $identifier)
            ->where('user_type', $type)
            ->update(['attempts' => 0, 'locked_until' => null]) > 0;
    }
}
