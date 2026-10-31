<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Préférence de notification d'un compte client.
 *
 * - type = 'global'  : basculeur général (désactive TOUT push) ;
 * - type = un des PushService::EVENT_TYPES : préférence par événement.
 * Absence de ligne = valeur par défaut : activé (contrat : « Par défaut :
 * tout activé, global activé »).
 */
class NotificationPreference extends Model
{
    public const GLOBAL = 'global';

    protected $table = 'notification_preferences';

    protected $fillable = ['user_id', 'type', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    /** user_id = id du compte client (cohérent avec notification_events.client_id). */
    public static function enabledFor(int $userId, string $type): bool
    {
        $global = self::where('user_id', $userId)->where('type', self::GLOBAL)->first();
        if ($global && ! $global->enabled) {
            return false;
        }

        $row = self::where('user_id', $userId)->where('type', $type)->first();

        return $row ? (bool) $row->enabled : true;
    }
}
