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

// Écart 3 : rappel de renouvellement d'abonnement (gabarit 14) — chaque jour à 08h00.
Schedule::command('abonnements:renouvellement-rappel --jours=7')->daily()->at('08:00')->withoutOverlapping()->onOneServer();
