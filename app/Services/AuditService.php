<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Parité AuditLogger PHP source : INSERT INTO audit_log
 * (action, user_id, user_type, details) + IP de la requête courante.
 */
class AuditService
{
    public static function log(string $action, ?int $userId, string $userType, string $details = ''): void
    {
        try {
            AuditLog::create([
                'action' => $action,
                'user_id' => $userId,
                'user_type' => $userType,
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            // L'audit ne doit jamais casser la requête (parité comportement PHP)
            Log::error('AuditLogger: '.$e->getMessage());
        }
    }

    /** IP réelle de la requête (REMOTE_ADDR côté PHP — pas de trust des X-Forwarded-For). */
    public static function ip(): ?string
    {
        return Request::ip();
    }
}
