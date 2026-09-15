<?php

namespace App\Services;

use App\Exceptions\PriceNotConfiguredException;
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
     *     expires_in_seconds: int|null
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
        ];
    }

    /**
     * @param  Collection<int, SeatLock>  $locks
     */
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
