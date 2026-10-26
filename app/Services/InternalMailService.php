<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Parité App\Service\MailService (legacy) : messagerie interne client<->admin.
 * Table : internal_messages (sender_id, sender_type, receiver_id,
 * receiver_type, content, is_read, created_at).
 * L020 : getThread marque lus les messages reçus par le client.
 */
class InternalMailService
{
    public function send(?int $senderId, string $senderType, ?int $receiverId, string $receiverType, string $content): void
    {
        DB::table('internal_messages')->insert([
            'sender_id' => $senderId,
            'sender_type' => $senderType,
            'receiver_id' => $receiverId,
            'receiver_type' => $receiverType,
            'content' => $content,
            'is_read' => 0,
            'created_at' => now(),
        ]);
    }

    public function getThread(int $clientId): array
    {
        $rows = DB::table('internal_messages')
            ->where(function ($q) use ($clientId) {
                $q->where('sender_id', $clientId)->orWhere('receiver_id', $clientId);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        // L020 : consultation par le client = messages reçus deviennent lus
        DB::table('internal_messages')
            ->where('receiver_id', $clientId)
            ->where('receiver_type', 'client')
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return $rows;
    }
}
