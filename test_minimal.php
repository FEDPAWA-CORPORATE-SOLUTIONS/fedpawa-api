<?php
echo "Starting mail test
";
require __DIR__ . '/vendor/autoload.php';
echo "Autoload loaded
";
$app = require_once __DIR__ . '/bootstrap/app.php';
echo "App bootstrapped
";
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
echo "Kernel resolved
";
$kernel->bootstrap();
echo "App bootstrapped successfully
";
use Illuminate\Support\Facades\Mail;
echo "Mail facade ready
";
Mail::raw('Test subject', function(\$message) {
    \$message->to('kamtchuengrayan@gmail.com')->subject('Test');
});
echo "Mail sent
";
