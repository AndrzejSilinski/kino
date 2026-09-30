<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\MovieAdminService;
use Illuminate\Console\Command;

/**
 * Sprzątanie osieroconych plakatów (Etap 10, blok C2).
 *
 * Cienka, jak pozostałe komendy harmonogramu: logika i warunki bezpieczeństwa siedzą
 * w MovieAdminService::pruneOrphanPosters(). --dry-run wypisuje, co zostałoby usunięte.
 */
class PruneOrphanPostersCommand extends Command
{
    protected $signature = 'cinema:posters:prune
        {--older-than=24 : Usuwa tylko pliki starsze niż tyle godzin (plik trwającego właśnie zapisu nie ma jeszcze wiersza w bazie)}
        {--dry-run : Tylko wypisz sieroty, niczego nie usuwaj}';

    protected $description = 'Usuwa pliki plakatów, których nie wskazuje żaden film';

    public function handle(MovieAdminService $movies): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $movies->pruneOrphanPosters((int) $this->option('older-than'), $dryRun);

        foreach ($result['orphans'] as $path) {
            $this->line(($dryRun ? 'sierota (bez usuwania): ' : 'usunięty: ').$path);
        }

        $this->info(sprintf(
            'Plakaty: sprawdzone %d, używane %d, świeże %d, obce nazwy %d, sieroty %d, usunięte %d',
            $result['checked'],
            $result['referenced'],
            $result['fresh'],
            $result['foreign'],
            count($result['orphans']),
            $result['deleted'],
        ));

        return self::SUCCESS;
    }
}
