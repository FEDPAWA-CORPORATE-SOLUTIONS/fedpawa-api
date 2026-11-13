<?php
error_reporting(E_ALL); ini_set('display_errors', '1');
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo 'Laravel app bootstrapped successfully!\n';

// Test if Mail facade can be resolved
try {
    $mail = $app->make(Illuminate\Support\Facades\Mail::class);
    echo 'Mail facade resolved!\n';
} catch (\Exception $e) {
    echo 'Mail facade error: ' . $e->getMessage() . "\n";
}
