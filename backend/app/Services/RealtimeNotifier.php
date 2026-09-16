<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Events\SalesActivity;
use App\Events\SeatsChanged;
use App\Events\SeatsResync;
use App\Models\Booking;
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
 *
 * BEZPIECZNIK (blok H): po porażce wysyłka jest wstrzymana na kilka sekund
 * dla wszystkich procesów, więc awaria Reverba kosztuje 0,5 s raz, a nie
 * przy każdej operacji. Szczegóły: RealtimeCircuitBreaker.
 */
final class RealtimeNotifier
{
    public function __construct(
        private readonly RealtimeCircuitBreaker $breaker,
    ) {}

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

    /**
     * Przejście rezerwacji: kanał właściciela + feed sprzedaży (blok G).
     *
     * Wołane PO COMMIT (DB::afterCommit w BookingService, słuchacz BookingPaid).
     * $status to przejście z tej transakcji, nie odczyt z bazy (patrz
     * BookingStatusChanged). Z bazy bierzemy tylko dane, które się nie
     * zmieniają: referencję, seans, kino, kwotę i liczbę miejsc.
     *
     * Pending (nowa rezerwacja) idzie tylko do feedu: klient poznaje
     * referencję z odpowiedzi checkoutu, więc nikt nie może jeszcze słuchać
     * kanału rezerwacji — wysyłka byłaby pustym żądaniem HTTP.
     */
    public function bookingChanged(int $bookingId, BookingStatus $status): bool
    {
        if ($this->breaker->isOpen()) {
            return false;
        }

        try {
            $booking = Booking::query()
                ->with(['screening.movie', 'screening.hall.cinema'])
                ->withCount('seatLocks')
                ->find($bookingId);
        } catch (Throwable $e) {
            // Transakcja jest już zatwierdzona — błąd odczytu nie może
            // zamienić udanej operacji w 500. Ta sama zasada co w send().
            $this->warn(SalesActivity::class, $e);

            return false;
        }

        if ($booking === null) {
            return false;
        }

        $now = now();
        $sent = true;

        if ($status !== BookingStatus::Pending) {
            $sent = $this->send(new BookingStatusChanged($booking->reference, $status, $now));
        }

        // Najpierw send(), potem &&: feed dostaje wpis także wtedy,
        // gdy wysyłka na kanał właściciela się nie udała.
        return $this->send(new SalesActivity($booking, $status, $now)) && $sent;
    }

    /** Wysyła zdarzenie; zwraca false, gdy się nie udało albo bezpiecznik jest otwarty. */
    public function send(ShouldBroadcastNow $event): bool
    {
        // Reverb niedawno zawiódł: nie płacimy kolejnego connect_timeout.
        // Klienci i tak odzyskają stan przez reconnect ze snapshotem.
        if ($this->breaker->isOpen()) {
            return false;
        }

        try {
            // event() rozwiązuje dispatcher w chwili wywołania, więc w testach
            // trafia do Event::fake(), a w aplikacji do broadcastera.
            event($event);

            return true;
        } catch (Throwable $e) {
            // Jedno ostrzeżenie na otwarcie — pominięte wysyłki nie zalewają logu.
            if ($this->breaker->trip()) {
                $this->warn($event::class, $e, $this->breaker->seconds());
            }

            return false;
        }
    }

    /** Ostrzeżenie bez komunikatu wyjątku (decyzja 62): tylko klasy. */
    private function warn(string $event, Throwable $e, int $pausedSeconds = 0): void
    {
        Log::warning('Nie udało się rozgłosić zdarzenia na żywo.', [
            'event' => $event,
            'exception' => $e::class,
            'paused_seconds' => $pausedSeconds,
        ]);
    }
}
