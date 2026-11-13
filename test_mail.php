<?php
echo "Starting test\n";
require __DIR__ . '/vendor/autoload.php';
echo "Autoload OK\n";
$app = require_once __DIR__ . '/bootstrap/app.php';
echo "Bootstrap OK\n";
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
echo "Kernel OK\n";
$kernel->bootstrap();
echo "Bootstrap done\n";
use Illuminate\Support\Facades\Mail;
Mail::raw('Test SMTP', function($message) {
    $message->to('kamtchuengrayan@gmail.com')->subject('Test');
});
echo "Mail sent command executed\n";
