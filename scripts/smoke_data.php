<?php

/**
 * Smoke test données réelles : requêtes Eloquent sur la copie de travail.
 * Usage : php artisan tinker scripts/smoke_data.php
 * (tinker execute le fichier avec le bootstrap Laravel)
 */

use App\Models\Admin;
use App\Models\Abonnement;
use App\Models\Client;
use App\Models\ContractTemplate;
use App\Models\Facture;
use Illuminate\Support\Facades\DB;

// Pointe sur la copie de travail (jamais l'original)
config(['database.connections.sqlite.database' => storage_path('fedpawa-copy.sqlite')]);
DB::purge('sqlite');
DB::reconnect('sqlite');
DB::setDefaultConnection('sqlite');

$checks = [];

// 1. clients (équivalent "users") — COUNT répond
$checks['clients_count'] = Client::count();

// 2. factures — COUNT répond
$checks['factures_count'] = Facture::count();

// 3. abonnements (équivalent "missions") — COUNT répond
$checks['abonnements_count'] = Abonnement::count();

// 4. Données réelles : l'admin seedé répond
$admin = Admin::where('email', 'mme.law@fedpawa.com')->first();
$checks['admin_email'] = $admin?->email;
$checks['admin_password_is_argon2'] = str_starts_with((string) $admin?->mot_de_passe, '$argon2id$');

// 5. Données réelles : contract_templates répond
$tpl = ContractTemplate::first();
$checks['template_name'] = $tpl?->name;
$checks['template_content_len'] = strlen((string) $tpl?->content);

// 6. Requête relationnelle : un client avec ses relations (vide mais doit répondre sans erreur)
$client = new Client();
$checks['relation_abonnements'] = is_countable($client->abonnements()->get()) ? 'ok' : 'fail';
$checks['relation_factures'] = is_countable($client->factures()->get()) ? 'ok' : 'fail';

// 7. FK matérielles : tentative d'insertion orpheline doit échouer
try {
    DB::table('abonnements')->insert([
        'client_id' => 999999,
        'pack' => 'test',
        'prix' => 0,
        'date_debut' => '2026-01-01 00:00:00',
    ]);
    $checks['fk_abonnements'] = 'FAIL: insert orpheline acceptée';
} catch (Exception $e) {
    $checks['fk_abonnements'] = 'ok (rejet FK)';
}

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$fail = array_filter($checks, fn ($v) => str_starts_with((string) $v, 'FAIL'));
exit($fail ? 1 : 0);
