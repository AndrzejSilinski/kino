<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\SeatsChanged;
use App\Events\SeatsResync;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wysyłka zdarzeń WebSocket z jednym miejscem na obsługę awarii (Etap 6, blok F).
 *
 * ZASADA: broadcast to POWIADOMIENIE, a nie warunek sprzedaży — jak listener
 * z decyzji 73. Blokada miejsca, płatność i wystawienie biletów są już
 * zatwierdzone w bazie, zanim tu trafimy (wywołanie idzie z DB::afterCommit).
 * Wyjątek z Reverba nie może więc zamienić udanej operacji w błąd 500.
 *
 * Czas porażki ogranicza client_options z config/broadcasting.php (blok B3):
 * 0,5 s na połączenie zamiast domyślnych 10 s.
 *
 * Log bez komunikatu wyjątku (jak decyzja 62): sama klasa wystarcza do
 * diagnozy, a treść błędu HTTP potrafi zawierać adres i fragment żądania.
 */
final class RealtimeNotifier
{
    /**
     * Zmiana stanu miejsc. Zbyt duży payload zamienia się w SeatsResync.
     *
     * @param  array<string, list<int>>  $seatsByStatus
     */
    public function seatsChanged(int $screeningId, int $version, array $seatsByStatus): bool
    {
        ksort($seatsByStatus);

        $seats = array_map(static function (array $ids): array {
            sort($ids);

            return $ids;
        }, $seatsByStatus);

        $event = new SeatsChanged($screeningId, $version, $seats);

        $bytes = strlen(json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR));

        if ($bytes > (int) config('broadcasting.max_payload_bytes', 8000)) {
            $event = new SeatsResync($screeningId, $version);
        }

        return $this->send($event);
    }

    /** Wysyła zdarzenie; zwraca false i loguje ostrzeżenie, gdy się nie udało. */
    public function send(ShouldBroadcastNow $event): bool
    {
        try {
            // event() rozwiązuje dispatcher w chwili wywołania, więc w testach
            // trafia do Event::fake(), a w aplikacji do broadcastera.
            event($event);

            return true;
        } catch (Throwable $e) {
            Log::warning('Nie udało się rozgłosić zdarzenia na żywo.', [
                'event' => $event::class,
                'exception' => $e::class,
            ]);

            return false;
        }
    }
}
