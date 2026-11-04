<?php

namespace App\Console\Commands;

use App\Models\Publication;
use Illuminate\Console\Command;

/**
 * CONTRAT-PUBLICATIONS.md §2 : une publication « programme » passe
 * automatiquement en « publie » à publie_le (scheduler chaque minute,
 * enregistré dans routes/console.php).
 */
class PublierProgrammees extends Command
{
    protected $signature = 'publications:publier-programmees';

    protected $description = 'Passe les publications « programme » dont publie_le est atteint en « publie ».';

    public function handle(): int
    {
        $count = Publication::where('statut', 'programme')
            ->whereNotNull('publie_le')
            ->where('publie_le', '<=', now())
            ->update(['statut' => 'publie']);

        if ($count > 0) {
            $this->info("{$count} publication(s) passée(s) en « publie ».");
        }

        return self::SUCCESS;
    }
}
