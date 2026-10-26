<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Service/AuditLogger.php : trace d'action dans audit_log.
 * Dégradation gracieuse : jamais d'échec métier si la table est absente.
 */
class AuditLogger
{
    public static function log(string $action, ?int $userId, string $userType, string $details = ''): void
    {
        try {
            DB::table('audit_log')->insert([
                'action' => $action,
                'user_id' => $userId,
                'user_type' => $userType,
                'details' => $details,
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
