<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Extension BE-EDGE du bootstrap schéma (complète BootstrapsBusinessSchema,
 * ne le remplace pas) : tables périphériques absentes du trait business —
 * tickets_support (avec reponse/reponse_at), contact_messages,
 * internal_messages, courriers.
 */
trait BootstrapsEdgeSchema
{
    protected function bootstrapEdgeSchema(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS contact_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nom TEXT NOT NULL,
            email TEXT NOT NULL,
            telephone TEXT,
            sujet TEXT NOT NULL,
            message TEXT NOT NULL,
            statut TEXT DEFAULT \'nouveau\',
            date_reception DATETIME
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS tickets_support (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            sujet TEXT NOT NULL,
            message TEXT NOT NULL,
            statut TEXT DEFAULT \'open\',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reponse TEXT,
            reponse_at DATETIME
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS internal_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER,
            sender_type TEXT NOT NULL,
            receiver_id INTEGER,
            receiver_type TEXT NOT NULL,
            content TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS courriers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            date_reception DATETIME NOT NULL,
            scan TEXT,
            statut TEXT DEFAULT \'non_lu\'
        )');
    }
}
