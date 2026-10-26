<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Setup du schéma métier source (schema_sqlite.sql) pour les tests Feature
 * BE-BUSINESS. La migration formelle appartient au worker DB ; ce trait
 * crée les tables/colonnes SI ABSENTES pour ne pas dépendre de son avancement
 * (CREATE TABLE IF NOT EXISTS, idempotent — parité MigrationRunner).
 */
trait BootstrapsBusinessSchema
{
    protected function bootstrapBusinessSchema(): void
    {
        // Migrations Laravel (users, personal_access_tokens) — FreshInMemoryDatabase
        // n'existe pas encore : on migre à la volée (idempotent sur :memory:).
        if (! \Schema::hasTable('users')) {
            \Artisan::call('migrate', ['--force' => true, '--path' => '/database/migrations']);
        }

        DB::statement('CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            civilité TEXT,
            nom TEXT NOT NULL,
            entreprise TEXT NOT NULL DEFAULT \'\',
            forme_juridique TEXT,
            rc TEXT,
            ifu TEXT,
            email TEXT NOT NULL UNIQUE,
            telephone TEXT,
            adresse TEXT,
            pays TEXT,
            activite TEXT,
            mot_de_passe TEXT NOT NULL,
            date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP,
            statut TEXT DEFAULT \'actif\',
            role TEXT DEFAULT \'client\'
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS abonnements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            pack TEXT NOT NULL,
            prix REAL NOT NULL,
            engagement INTEGER DEFAULT 0,
            date_debut DATETIME NOT NULL,
            date_fin DATETIME,
            statut TEXT DEFAULT \'en_attente\',
            details TEXT
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS paiements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            montant REAL NOT NULL,
            mode_paiement TEXT NOT NULL,
            transaction_id TEXT,
            date DATETIME NOT NULL,
            statut TEXT DEFAULT \'en_attente\'
        )');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_paiements_transaction ON paiements(transaction_id)');

        DB::statement('CREATE TABLE IF NOT EXISTS factures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            numero TEXT NOT NULL UNIQUE,
            montant REAL NOT NULL,
            date DATETIME NOT NULL,
            lien_pdf TEXT
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS contrats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            fichier TEXT NOT NULL,
            date_signature DATETIME NOT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS kyc (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            piece_identite TEXT,
            statuts TEXT DEFAULT \'en_cours\',
            justificatif_domicile TEXT,
            description_activite TEXT,
            date_soumission DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS contract_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            content TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            user_id INTEGER,
            user_type TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            details TEXT
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id TEXT NOT NULL UNIQUE,
            payload TEXT,
            processed_at DATETIME
        )');

        $this->addColumnIfMissing('abonnements', 'details', 'ALTER TABLE abonnements ADD COLUMN details TEXT');

        // Seed template (parité MigrationRunner : contenu du twig source)
        $has = DB::table('contract_templates')->where('name', 'contract')->count();
        if ($has === 0) {
            $content = file_get_contents(resource_path('views/pdf/contract-template.html'));
            if ($content === false || trim($content) === '') {
                $content = (string) @file_get_contents('/root/Fedpawa/fedpawa_light/templates/contract.html.twig');
            }
            if (is_string($content) && trim($content) !== '') {
                DB::table('contract_templates')->insert(['name' => 'contract', 'content' => $content]);
            }
        }
    }

    protected function addColumnIfMissing(string $table, string $column, string $ddl): void
    {
        $cols = collect(DB::select("PRAGMA table_info({$table})"))->pluck('name')->all();
        if (! in_array($column, $cols, true)) {
            DB::statement($ddl);
        }
    }

    /** Client métier + user Sanctum aligné (email partagé). @return array{id:int,user:\App\Models\User} */
    protected function businessClient(string $email = 'client@example.com', string $role = 'client'): array
    {
        $existing = DB::table('clients')->where('email', $email)->first();
        $clientId = $existing
            ? (int) $existing->id
            : (int) DB::table('clients')->insertGetId([
                'nom' => 'Jean Dupont',
                'entreprise' => 'FEDPAWA TEST SARL',
                'email' => $email,
                'mot_de_passe' => 'x',
                'date_inscription' => now()->format('Y-m-d H:i:s'),
                'statut' => 'actif',
                'role' => $role,
            ]);
        $user = User::where('email', $email)->first()
            ?? User::create([
                'name' => 'Jean Dupont',
                'email' => $email,
                'password' => 'secret-password',
            ]);

        return ['id' => $clientId, 'user' => $user];
    }
}
