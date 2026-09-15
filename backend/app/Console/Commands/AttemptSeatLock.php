<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\CinemaException;
use App\Models\Screening;
use App\Services\SeatLockService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pojedyncza próba zablokowania miejsc — narzędzie testu współbieżności.
 *
 * Test uruchamia N kopii tej komendy w osobnych procesach systemowych. Każdy proces
 * boot-uje Laravel od zera i otwiera WŁASNE połączenie do PostgreSQL, więc kontencja
 * jest prawdziwa — taka sama, jak przy N równoczesnych requestach HTTP.
 *
 * Komenda komunikuje się z testem przez stdout (jedna linia JSON) i kod wyjścia.
 * Nie loguje nic więcej, żeby parsowanie wyniku było jednoznaczne.
 */
class AttemptSeatLock extends Command
{
    protected $signature = 'cinema:seat-lock:attempt
                            {--screening= : ID seansu}
                            {--seats= : ID miejsc oddzielone przecinkami}
                            {--session= : Identyfikator sesji zgłaszającej}
                            {--at= : Bariera startu — microtime(true), przed którym proces czeka}';

    protected $description = 'Jedna próba blokady miejsc (narzędzie testu współbieżności).';

    public function handle(SeatLockService $seatLocks): int
    {
        $sessionId = (string) $this->option('session');

        try {
            // Wszystko, co kosztuje czas — boot frameworka, połączenie z bazą,
            // pobranie seansu — dzieje się PRZED barierą. Za barierą zostaje
            // wyłącznie sama próba blokady.
            $screening = Screening::findOrFail((int) $this->option('screening'));
            $seatIds = array_map('intval', explode(',', (string) $this->option('seats')));

            $this->waitForStartingGun((float) $this->option('at'));

            $locks = $seatLocks->lock($screening, $seatIds, $sessionId);

            $this->emit([
                'session' => $sessionId,
                'result' => 'locked',
                'seats' => $locks->pluck('seat_id')->all(),
            ]);

            return self::SUCCESS;
        } catch (CinemaException $e) {
            // Kontrolowana odmowa — dokładnie to, co API zwróci jako 409 albo 422.
            $this->emit([
                'session' => $sessionId,
                'result' => 'rejected',
                'error_code' => $e->errorCode(),
                'status' => $e->status(),
            ]);

            return self::FAILURE;
        } catch (Throwable $e) {
            // Cokolwiek innego to porażka testu: żaden wyjątek techniczny
            // (QueryException, deadlock, timeout) nie ma prawa tu dolecieć.
            $this->emit([
                'session' => $sessionId,
                'result' => 'crashed',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return 2;
        }
    }

    /**
     * Czeka do wspólnego znacznika czasu, żeby wszystkie procesy uderzyły naraz.
     * Krótki usleep zamiast pustej pętli — nie palimy CPU przy 20 procesach.
     */
    private function waitForStartingGun(float $startAt): void
    {
        while ($startAt > 0.0 && microtime(true) < $startAt) {
            usleep(200);
        }
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        $this->output->writeln(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
