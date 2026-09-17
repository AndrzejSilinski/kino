<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Exceptions\PriceNotConfiguredException;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\SeatLock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Wycena koszyka PO STRONIE SERWERA.
 *
 * Serwis nie przyjmuje żadnej kwoty z zewnątrz. Jedynym wejściem jest
 * seans i identyfikator sesji; ceny czyta z screening_prices, a listę
 * miejsc z aktywnych blokad. Klient nie ma jak wpłynąć na wynik.
 *
 * W Etapie 4 to stąd weźmiemy kwotę Payment Intentu — dlatego metoda
 * zwraca też czas wygaśnięcia koszyka. Płatność musi zdążyć przed
 * wygaśnięciem najkrótszej blokady, inaczej pobralibyśmy pieniądze
 * za miejsce, którego już nie ma (race condition z sekcji 1.4 zadania).
 */
class CartPricingService
{
    public function __construct(
        private readonly SeatLockService $seatLocks,
    ) {}

    /**
     * @return array{
     *     seats: array<int, array<string, mixed>>,
     *     seats_count: int,
     *     total: array<string, mixed>,
     *     expires_at: string|null,
     *     expires_in_seconds: int|null,
     *     pending_booking: array{reference: string, expires_at: string, expires_in_seconds: int}|null
     * }
     *
     * @throws PriceNotConfiguredException
     */
    public function forSession(Screening $screening, string $sessionId): array
    {
        $locks = $this->seatLocks->activeLocksFor($screening, $sessionId);

        // Jawne doładowanie relacji na całej kolekcji: jedno zapytanie
        // na miejsca, jedno na kategorie — niezależnie od liczby blokad.
        // Bez tego preventLazyLoading rzuci wyjątkiem przy pierwszym
        // odczycie $lock->seat, i słusznie.
        $locks->load('seat.priceCategory');

        $prices = $screening->prices()->pluck('price', 'price_category_id')->all();

        $now = CarbonImmutable::now();
        $items = [];
        $total = 0;

        foreach ($locks as $lock) {
            $seat = $lock->seat;
            $categoryId = (int) $seat->price_category_id;

            // Brak ceny = błąd danych. Nie zgadujemy i nie przyjmujemy zera.
            if (! array_key_exists($categoryId, $prices)) {
                throw new PriceNotConfiguredException($categoryId);
            }

            $priceMinor = (int) $prices[$categoryId];
            $total += $priceMinor;

            $items[] = [
                'seat_id' => $seat->id,
                'row' => $seat->row_label,
                'number' => $seat->seat_number,
                'label' => $seat->label,
                'type' => $seat->type->value,
                'category' => [
                    'id' => $seat->priceCategory?->id,
                    'name' => $seat->priceCategory?->name,
                    'color' => $seat->priceCategory?->color,
                ],
                'price' => Money::minor($priceMinor)->toArray(),
                'lock_expires_at' => $lock->expires_at->toIso8601String(),
            ];
        }

        // Koszyk wygasa razem z NAJWCZEŚNIEJSZĄ blokadą. Timer na ekranie
        // podsumowania musi pokazywać ten moment, a nie średnią ani
        // najpóźniejszy — inaczej klient zobaczy "zostało 4:00", gdy
        // pierwsze miejsce zwolni się za 30 sekund.
        $expiresAt = $this->earliestExpiry($locks);

        return [
            'seats' => $items,
            'seats_count' => count($items),
            'total' => Money::minor($total)->toArray(),
            'expires_at' => $expiresAt?->toIso8601String(),
            // Liczba sekund obok daty: zegar telefonu bywa przestawiony,
            // a odliczanie z różnicy dat na urządzeniu ze złą strefą to
            // klasyczny błąd. Datą klient się resynchronizuje, sekundami
            // odlicza.
            'expires_in_seconds' => $expiresAt !== null
                ? max(0, (int) $now->diffInSeconds($expiresAt, false))
                : null,
            'pending_booking' => $this->pendingBooking($locks, $now),
        ];
    }

    /**
     * @param  Collection<int, SeatLock>  $locks
     */
    /**
     * Rozpoczęta płatność za miejsca z koszyka (Etap 8, blok H1).
     *
     * Po checkoucie blokady są wpięte w rezerwację (seat_locks.booking_id) i należą
     * do płatności: DELETE ich nie zwalnia, a dobranie miejsca kończy się przy kolejnym
     * checkoucie błędem BOOKING_ALREADY_PENDING. Bez tej informacji klient nie wie,
     * że plan sali trzeba zamrozić, a po F5 — dokąd wrócić. Stan podaje serwer,
     * bo tylko on go zna (sessionStorage karty zgadywałby, a aplikacja mobilna
     * musiałaby zgadywać po swojemu).
     *
     * Jedno zapytanie tylko wtedy, gdy któraś blokada ma booking_id. Aktywna blokada
     * wpięta w rezerwację oznacza rezerwację pending (opłacenie i wygaśnięcie zwalniają
     * blokady), ale warunek statusu jest jawny — nie wnioskujemy go z cudzej reguły.
     *
     * @param  Collection<int, SeatLock>  $locks
     * @return array{reference: string, expires_at: string, expires_in_seconds: int}|null
     */
    private function pendingBooking(Collection $locks, CarbonImmutable $now): ?array
    {
        $bookingId = $locks->whereNotNull('booking_id')->pluck('booking_id')->first();

        if ($bookingId === null) {
            return null;
        }

        $booking = Booking::query()
            ->whereKey($bookingId)
            ->where('status', BookingStatus::Pending)
            ->first(['id', 'reference', 'expires_at']);

        if ($booking === null || $booking->expires_at === null) {
            return null;
        }

        $expiresAt = CarbonImmutable::parse($booking->expires_at);

        return [
            'reference' => $booking->reference,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => max(0, (int) $now->diffInSeconds($expiresAt, false)),
        ];
    }

    private function earliestExpiry(Collection $locks): ?CarbonImmutable
    {
        $earliest = null;

        foreach ($locks as $lock) {
            $expiry = CarbonImmutable::parse($lock->expires_at);

            if ($earliest === null || $expiry->lessThan($earliest)) {
                $earliest = $expiry;
            }
        }

        return $earliest;
    }
}
