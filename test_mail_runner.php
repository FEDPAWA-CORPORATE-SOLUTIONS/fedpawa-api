<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

try {
    Mail::raw('Ceci est un mail de test SMTP envoyé le ' . date('Y-m-d H:i:s'), function ($message) {
        $message->to('kamtchuengrayan@gmail.com')
                ->subject('Test SMTP Live Fedpawa');
    });
    echo "SUCCESS: Mail envoyé via la façade Mail de Laravel.\n";
    Log::info("Test SMTP email envoyé avec succès à kamtchuengrayan@gmail.com");
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    Log::error("Test SMTP email a échoué: " . $e->getMessage());
}
