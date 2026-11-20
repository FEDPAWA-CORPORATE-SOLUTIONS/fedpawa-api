<?php
require '/app/vendor/autoload.php';
$app = require_once '/app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\CampayService;

$campay = new CampayService();
$phone = '237672039863';
$amounts = [1.0, 50.0, 100.0];
foreach ($amounts as $i => $amount) {
    $ref = 'TEST_FINAL_' . date('His') . '_' . $i;
    echo "APPEL " . ($i+1) . ": {$amount} FCFA sur {$phone} (ref: {$ref})\n";
    try {
        $result = $campay->collect($amount, $phone, $ref);
        echo "RESULTAT: ";
        var_dump($result);
    } catch (\Throwable $e) {
        echo "ERREUR: " . $e->getMessage() . "\n";
    }
    if ($i < 2) {
        echo "Attente 45 secondes...\n";
        sleep(45);
    }
}
echo "FIN DES TESTS\n";
