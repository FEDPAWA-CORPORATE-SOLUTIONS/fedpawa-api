<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ContractSigningRequest;
use Illuminate\Support\Carbon;

class PortantSignCleanupCommand extends Command
{
    protected $signature = 'portant:cleanup';
    protected $description = 'Nettoie les demandes de signature expirées (plus de 14 jours)';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(14);

        $deleted = ContractSigningRequest::where('created_at', '<', $cutoff)
            ->where('status', '!=', 'signed')
            ->delete();

        $this->info("Nettoyage terminé : $deleted demande(s) de signature expirée(s) supprimée(s).");

        return 0;
    }
}
