<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CONTRAT-PUBLICATIONS.md §2 : une publication « programme » passe automatiquement
// en « publie » à l'heure prévue (publie_le) — scheduler chaque minute.
Schedule::command('publications:publier-programmees')->everyMinute()->withoutOverlapping()->onOneServer();
