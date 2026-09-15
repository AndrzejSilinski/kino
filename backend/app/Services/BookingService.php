<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\BookingAlreadyPendingException;
use App\Exceptions\BookingNotPayableException;
use App\Exceptions\EmptyCartException;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\Seat;
use App\Models\SeatLock;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Maszyna stanów rezerwacji. Wszystkie przejścia w jednym miejscu.
 *
 * Ta klasa NIE zna Stripe'a — operuje wyłącznie na bazie. Dzięki temu
 * wyścig o miejsca da się przetestować bez sieci i bez konta u dostawcy,
 * a PaymentService (krok 7) tylko spina ją z bramką płatności.
 *
 * DWIE REGUŁY, NA KTÓRYCH OPIERA SIĘ CAŁOŚĆ:
 *
 * 1. Najpierw miejsce, potem pieniądze. Bilety powstają PRZED pobraniem
 *    pieniędzy, bo cofnięcie biletu jest darmowe i niewidoczne dla
 *    klienta, a cofnięcie płatności to zwrot: wolny i widoczny na
 *    wyciągu. Krok tańszy do cofnięcia idzie pierwszy.
 *
 * 2. O tym, czy miejsce jest nasze, decyduje wiersz w seat_locks, a nie
 *    zegar. Dlatego przy wystawianiu biletów NIE sprawdzamy expires_at.
 *    Wygasła, ale niezwolniona blokada nadal zajmuje indeks częściowy
 *    (pułapka A), więc nikt inny nie mógł tego miejsca zająć.
 */
class BookingService
{
    public function __construct(
        private readonly CartPricingService $cartPricing,
        private readonly SeatStateRecorder $seatStates,
        private readonly RealtimeNotifier $realtime,
    ) {}

    /**
     * Koszyk sesji zakupowej -> rezerwacja w stanie pending.
     *
     * Wycenę koszyka wołamy PRZED transakcją: to ona pilnuje, że każda
     * kategoria ma cenę w cenniku seansu, i rzuca PRICE_NOT_CONFIGURED,
     * zanim cokolwiek zapiszemy. Nigdy nie przyjmujemy kwoty z zewnątrz.
     *
     * @throws EmptyCartException|BookingAlreadyPendingException
     */
    public function checkout(Screening $screening, string $sessionId, User $user): Booking
    {
        $this->cartPricing->forSession($screening, $sessionId);

        return DB::transaction(function () use ($screening, $sessionId, $user): Booking {
            $now = CarbonImmutable::now();

            // Blokady sesji zamrożone na czas transakcji. orderBy('seat_id')
            // to ta sama ochrona przed deadlockiem co w SeatLockService:
            // wszyscy zakładają locki w tej samej kolejności.
            $locks = SeatLock::query()
                ->where('screening_id', $screening->id)
                ->where('session_id', $sessionId)
                ->whereNull('released_at')
                ->where('expires_at', '>', $now)
                ->orderBy('seat_id')
                ->lockForUpdate()
                ->get();

            if ($locks->isEmpty()) {
                throw new EmptyCartException;
            }

            // Podwójne kliknięcie "Zapłać": druga transakcja czeka na
            // lockForUpdate, a potem widzi blokady wpięte już w rezerwację.
            if ($existing = $this->existingBookingFor($locks)) {
                return $existing;
            }

            $prices = $this->priceSeats($screening, $locks->pluck('seat_id')->all());

            $booking = new Booking;
            $booking->user_id = $user->id;
            $booking->screening_id = $screening->id;
            $booking->status = BookingStatus::Pending;
            $booking->total_amount = array_sum($prices);
            // Okno płatności liczymy od teraz, a nie od TTL blokady:
            // klient, który dotarł do kasy z 20 sekundami na liczniku,
            // nie ma szans wpisać danych karty i przejść 3-D Secure.
            $booking->expires_at = $now->addSeconds((int) config('payments.window_seconds'));
            $booking->save();

            // Blokady dostają termin rezerwacji. Bez tego SeatLockService
            // uznałby je za wygasłe po pierwotnym TTL i oddał miejsca
            // innej sesji w trakcie płatności.
            SeatLock::query()
                ->whereIn('id', $locks->pluck('id'))
                ->update([
                    'booking_id' => $booking->id,
                    'expires_at' => $booking->expires_at,
                    'updated_at' => $now,
                ]);

            // Feed sprzedaży po COMMIT (Etap 6, blok G). Podwójne kliknięcie
            // wychodzi wcześniej przez existingBookingFor — bez drugiego wpisu.
            $bookingId = (int) $booking->id;
            DB::afterCommit(fn () => $this->realtime->bookingChanged($bookingId, BookingStatus::Pending));

            return $booking;
        });
    }

    /**
     * Blokady -> bilety. Wołane po autoryzacji płatności.
     *
     * Idempotentne: powtórzony webhook zastaje bilety na miejscu i nic
     * nie robi. Gdyby nawet przeszedł dalej, INSERT rozbiłby się o indeks
     * tickets_active_seat_unique — to ostatnia linia obrony.
     *
     * @throws BookingNotPayableException|SeatsUnavailableException
     */
    public function fulfil(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $now = CarbonImmutable::now();

            $fresh = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($fresh->tickets()->exists()) {
                return $fresh;
            }

            if ($fresh->status !== BookingStatus::Pending) {
                throw new BookingNotPayableException($fresh->status);
            }

            // CELOWO bez warunku expires_at: patrz reguła 2 w nagłówku klasy.
            $locks = SeatLock::query()
                ->where('booking_id', $fresh->id)
                ->whereNull('released_at')
                ->orderBy('seat_id')
                ->lockForUpdate()
                ->get();

            $prices = $this->priceSeats($fresh->screening, $locks->pluck('seat_id')->all());

            // Suma cen miejsc, które NADAL trzymamy, musi się zgadzać
            // z kwotą rezerwacji. Jeśli którekolwiek miejsce zostało nam
            // odebrane, suma jest mniejsza — i nie wystawiamy niczego.
            // Nie trzeba do tego pamiętać pierwotnej liczby foteli.
            if ($locks->isEmpty() || array_sum($prices) !== (int) $fresh->total_amount) {
                throw new SeatsUnavailableException([], []);
            }

            foreach ($locks as $lock) {
                // Bilety zapisujemy pojedynczo, bo kod QR nadaje zdarzenie
                // creating modelu, a insert() hurtem by je pominął.
                $ticket = new Ticket;
                $ticket->booking_id = $fresh->id;
                $ticket->screening_id = $fresh->screening_id;
                $ticket->seat_id = $lock->seat_id;
                $ticket->price = $prices[$lock->seat_id];
                $ticket->status = TicketStatus::Valid;
                $ticket->save();
            }

            SeatLock::query()
                ->whereIn('id', $locks->pluck('id'))
                ->update(['released_at' => $now, 'updated_at' => $now]);

            $fresh->status = BookingStatus::Paid;
            $fresh->paid_at = $now;
            $fresh->save();

            // Wersja stanu miejsc (Etap 6) — ostatnia instrukcja transakcji.
            $this->seatStates->record((int) $fresh->screening_id, [
                SeatStateRecorder::SOLD => $locks->pluck('seat_id')->all(),
            ]);

            return $fresh;
        });
    }

    /**
     * Wygasza nieopłaconą rezerwację i oddaje miejsca do puli.
     *
     * Zwraca false, gdy rezerwacja nie jest już pending — czyli gdy
     * webhook nas wyprzedził. Oba procesy biorą lockForUpdate na tym
     * samym wierszu, więc wykonują się po kolei i nie ma stanu pośredniego.
     */
    public function expire(Booking $booking): bool
    {
        return $this->finish($booking, BookingStatus::Expired);
    }

    /** Rezygnacja klienta albo anulowana płatność u dostawcy. */
    public function cancel(Booking $booking): bool
    {
        return $this->finish($booking, BookingStatus::Cancelled);
    }

    /**
     * Rezerwacje pending po terminie — wejście dla schedulera.
     *
     * @return Collection<int, Booking>
     */
    public function dueForExpiry(int $limit = 100): Collection
    {
        return Booking::query()
            ->where('status', BookingStatus::Pending)
            ->where('expires_at', '<=', CarbonImmutable::now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();
    }

    private function finish(Booking $booking, BookingStatus $status): bool
    {
        return DB::transaction(function () use ($booking, $status): bool {
            $now = CarbonImmutable::now();

            $fresh = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== BookingStatus::Pending) {
                return false;
            }

            // FOR UPDATE zamiast samego UPDATE: sweep mógł część blokad zwolnić
            // wcześniej. Wersja ma objąć tylko miejsca zwolnione TUTAJ (Etap 6).
            $locks = SeatLock::query()
                ->where('booking_id', $fresh->id)
                ->whereNull('released_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'seat_id']);

            if ($locks->isNotEmpty()) {
                SeatLock::query()
                    ->whereIn('id', $locks->pluck('id'))
                    ->update(['released_at' => $now, 'updated_at' => $now]);
            }

            $fresh->status = $status;
            $fresh->save();

            $this->seatStates->record((int) $fresh->screening_id, [
                SeatStateRecorder::FREE => $locks->pluck('seat_id')->all(),
            ]);

            // Po seats.changed (zarejestrowanym wyżej) — ta sama kolejność po COMMIT.
            $bookingId = (int) $fresh->id;
            DB::afterCommit(fn () => $this->realtime->bookingChanged($bookingId, $status));

            return true;
        });
    }

    /** @param Collection<int, SeatLock> $locks */
    private function existingBookingFor(Collection $locks): ?Booking
    {
        $attached = $locks->whereNotNull('booking_id');

        if ($attached->isEmpty()) {
            return null;
        }

        $booking = Booking::query()
            ->whereKey($attached->first()->booking_id)
            ->lockForUpdate()
            ->first();

        $sameSet = $attached->count() === $locks->count()
            && $attached->pluck('booking_id')->unique()->count() === 1;

        if ($sameSet && $booking?->status === BookingStatus::Pending) {
            return $booking;
        }

        throw new BookingAlreadyPendingException($booking?->reference);
    }

    /**
     * Ceny miejsc z cennika seansu: seat_id => grosze.
     *
     * @param  list<int>  $seatIds
     * @return array<int, int>
     */
    private function priceSeats(Screening $screening, array $seatIds): array
    {
        if ($seatIds === []) {
            return [];
        }

        $seats = Seat::query()->whereIn('id', $seatIds)->get(['id', 'price_category_id']);
        $prices = [];

        foreach ($seats as $seat) {
            $price = $screening->priceFor($seat->price_category_id);

            if ($price === null) {
                // Wycena koszyka sprawdziła to wcześniej, więc tutaj brak
                // ceny oznacza zmianę cennika w trakcie checkoutu.
                throw new LogicException('Brak ceny dla kategorii miejsca '.$seat->price_category_id);
            }

            $prices[$seat->id] = $price;
        }

        return $prices;
    }

    /**
     * Pieniądze zwrócone, miejsca wracają do puli.
     *
     * Osobny status od anulowania, bo dla panelu admina i dla księgowości
     * to dwie różne sytuacje: przy cancelled nic nie pobrano, przy
     * refunded pieniądze wpłynęły i zostały oddane.
     */
    public function markRefunded(Booking $booking): bool
    {
        return $this->finish($booking, BookingStatus::Refunded);
    }

    /**
     * Wycofanie już wystawionych biletów.
     *
     * Sytuacja: miejsca stały się nasze, ale pobranie pieniędzy przepadło
     * (autoryzacja anulowana poza systemem albo wygasła). Bilety dostają
     * status cancelled i dzięki temu WYPADAJĄ z indeksu częściowego
     * tickets_active_seat_unique — miejsca wracają do sprzedaży, a ślad
     * po transakcji zostaje.
     */
    public function revoke(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking): bool {
            $now = CarbonImmutable::now();

            $fresh = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== BookingStatus::Paid) {
                return false;
            }

            // Do puli wracają miejsca z biletów, które nie były jeszcze anulowane.
            // Wiersz rezerwacji jest pod FOR UPDATE, więc zbiór jej biletów nie
            // zmieni się między tym odczytem a UPDATE-em (Etap 6).
            $seatIds = Ticket::query()
                ->where('booking_id', $fresh->id)
                ->where('status', '!=', TicketStatus::Cancelled)
                ->pluck('seat_id')
                ->all();

            Ticket::query()
                ->where('booking_id', $fresh->id)
                ->update(['status' => TicketStatus::Cancelled, 'updated_at' => $now]);

            $fresh->status = BookingStatus::Cancelled;
            $fresh->save();

            $this->seatStates->record((int) $fresh->screening_id, [
                SeatStateRecorder::FREE => $seatIds,
            ]);

            $bookingId = (int) $fresh->id;
            DB::afterCommit(fn () => $this->realtime->bookingChanged($bookingId, BookingStatus::Cancelled));

            return true;
        });
    }
}
