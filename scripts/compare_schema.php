<?php

/**
 * Comparaison programmatique : schéma migré (Laravel) vs schema_sqlite.sql (référence).
 * Usage : php scripts/compare_schema.php [chemin_db_laravel]
 * Exit 0 = parité, exit 1 = écarts (liste affichée).
 */

$refSql = file_get_contents('/root/Fedpawa/fedpawa_light/schema_sqlite.sql');
$laraDbPath = $argv[1] ?? __DIR__ . '/../database/database.sqlite';

// --- 1. DB de référence : exécution brute du SQL source ---
$ref = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ref->exec($refSql);

// --- 2. DB Laravel migrée ---
$lara = new PDO('sqlite:' . $laraDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function affinity(string $type): string
{
    // Règles d'affinité SQLite (https://sqlite.org/datatype3.html §3.1)
    $t = strtoupper($type);
    if ($t === '') { return 'BLOB'; }
    if (str_contains($t, 'INT')) { return 'INTEGER'; }
    if (str_contains($t, 'CHAR') || str_contains($t, 'CLOB') || str_contains($t, 'TEXT')) { return 'TEXT'; }
    if ($t === 'BLOB' || str_contains($t, 'BLOB')) { return 'BLOB'; }
    if (str_contains($t, 'REAL') || str_contains($t, 'FLOA') || str_contains($t, 'DOUB')) { return 'REAL'; }
    return 'NUMERIC';
}

function normalizeDefault(?string $d): string
{
    if ($d === null) { return ''; }
    $d = trim($d);
    // unifier guillemets et casse des mots-clés
    $lower = strtolower($d);
    if ($lower === 'current_timestamp') { return 'current_timestamp'; }
    return trim($d, "'");
}

function schemaOf(PDO $db): array
{
    $out = ['tables' => [], 'indexes' => []];
    $tables = [];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
        $tables[] = $r['name'];
    }
    foreach ($tables as $t) {
        $cols = [];
        $res = $db->query("PRAGMA table_info(" . $db->quote($t) . ")");
        foreach ($res as $c) {
            $cols[$c['name']] = [
                'pos' => (int) $c['cid'],
                'type' => $c['type'],
                'affinity' => affinity($c['type']),
                'notnull' => (int) $c['notnull'],
                'default' => normalizeDefault($c['dflt_value']),
                'pk' => (int) $c['pk'],
            ];
        }
        $out['tables'][$t] = $cols;

        $idx = [];
        $li = $db->query("PRAGMA index_list(" . $db->quote($t) . ")");
        foreach ($li as $ir) {
            $name = $ir['name'];
            $colsIdx = [];
            $ic = $db->query("PRAGMA index_info(" . $db->quote($name) . ")");
            foreach ($ic as $icr) {
                $colsIdx[] = $icr['name'];
            }
            $idx[$name] = [
                'unique' => (int) $ir['unique'],
                'cols' => implode(',', $colsIdx),
                'origin' => $ir['origin'],
            ];
        }
        $out['indexes'][$t] = $idx;
    }
    return $out;
}

$refSchema = schemaOf($ref);
$laraSchema = schemaOf($lara);

$errors = [];
$info = [];

// --- Tables ---
$refTables = array_keys($refSchema['tables']);
$laraTables = array_keys($laraSchema['tables']);
$missing = array_diff($refTables, $laraTables);
$extra = array_diff($laraTables, $refTables);
if ($missing) { $errors[] = 'Tables manquantes côté Laravel : ' . implode(', ', $missing); }
if ($extra) { $info[] = 'Tables Laravel hors référence (Laravel framework) : ' . implode(', ', $extra); }

$nbMatched = 0;
foreach ($refTables as $t) {
    if (!isset($laraSchema['tables'][$t])) { continue; }
    $nbMatched++;
    $rc = $refSchema['tables'][$t];
    $lc = $laraSchema['tables'][$t];

    // Colonnes manquantes/extras
    $cm = array_diff(array_keys($rc), array_keys($lc));
    $ce = array_diff(array_keys($lc), array_keys($rc));
    if ($cm) { $errors[] = "[$t] colonnes manquantes : " . implode(', ', $cm); }
    if ($ce) { $errors[] = "[$t] colonnes en trop : " . implode(', ', $ce); }

    // Ordre + attributs
    $rpos = array_values(array_map(fn ($c) => $c['pos'], $rc));
    foreach ($rc as $name => $rdef) {
        if (!isset($lc[$name])) { continue; }
        $ldef = $lc[$name];
        if ($rdef['affinity'] !== $ldef['affinity']) {
            $errors[] = "[$t.$name] type différent : ref={$rdef['type']} ({$rdef['affinity']}) vs laravel={$ldef['type']} ({$ldef['affinity']})";
        }
        if ($rdef['notnull'] !== $ldef['notnull']) {
            $errors[] = "[$t.$name] nullabilité différente : ref notnull={$rdef['notnull']} vs laravel notnull={$ldef['notnull']}";
        }
        if ($rdef['pk'] !== $ldef['pk']) {
            $errors[] = "[$t.$name] pk différent : ref={$rdef['pk']} vs laravel={$ldef['pk']}";
        }
        if ($rdef['default'] !== $ldef['default']) {
            $errors[] = "[$t.$name] défaut différent : ref='{$rdef['default']}' vs laravel='{$ldef['default']}'";
        }
        if ($rdef['pos'] !== $ldef['pos']) {
            $errors[] = "[$t.$name] ordre différent : ref pos={$rdef['pos']} vs laravel pos={$ldef['pos']}";
        }
    }

    // Indexes : comparer par (unique, colonnes) — les noms générés diffèrent (autoindex)
    $ridx = array_map(fn ($i) => ($i['unique'] ? 'u:' : 'i:') . $i['cols'], array_filter($refSchema['indexes'][$t], fn ($i) => $i['origin'] !== 'pk'));
    $lidx = array_map(fn ($i) => ($i['unique'] ? 'u:' : 'i:') . $i['cols'], array_filter($laraSchema['indexes'][$t], fn ($i) => $i['origin'] !== 'pk'));
    sort($ridx); sort($lidx);
    if ($ridx !== $lidx) {
        $errors[] = "[$t] indexes différents : ref=[" . implode(' ; ', $ridx) . "] vs laravel=[" . implode(' ; ', $lidx) . "]";
    }
}

echo "Tables référence : " . count($refTables) . "\n";
echo "Tables Laravel matched : $nbMatched\n";
foreach ($info as $i) { echo "INFO: $i\n"; }

if ($errors) {
    echo "\n=== ÉCARTS (" . count($errors) . ") ===\n";
    foreach ($errors as $e) { echo "- $e\n"; }
    exit(1);
}
echo "\nPARITÉ OK : tables, colonnes, types (affinité), nullabilité, défauts, PK et indexes identiques.\n";
exit(0);
