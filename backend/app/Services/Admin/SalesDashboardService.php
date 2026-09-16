<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\BookingStatus;
use App\Enums\ScreeningStatus;
use App\Enums\TicketStatus;
use App\Events\SalesActivity;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Liczby pulpitu panelu (Etap 7, blok L; wymóg 2.3). Bez cache: to kilka zapytań
 * po indeksach, a panel ma pokazywać stan z tej chwili.
 *
 * "DZIŚ" TO DOBA W STREFIE KINA. Kina sieci mogą mieć różne strefy, więc granice
 * liczymy osobno dla każdej strefy (kina grupujemy po timezone) i łączymy warunkiem
 * OR. Granice to przedział półotwarty [północ, następna północ) w UTC — porównanie
 * z paid_at trafia w indeks, w przeciwieństwie do (paid_at AT TIME ZONE …)::date.
 * Następną północ liczymy w strefie kina, więc doba zmiany czasu ma 23 albo 25 godzin.
 *
 * DEFINICJE (te same w widoku i README):
 *   sprzedaż brutto — suma rezerwacji opłaconych dziś (paid_at), które mają status
 *                     paid albo refunded: w obu przypadkach pieniądze wpłynęły;
 *   zwroty          — suma rezerwacji refunded ze zwrotem zakończonym dziś;
 *   netto           — brutto minus zwroty (zwrot może dotyczyć wczorajszej sprzedaży);
 *   bilety          — nieanulowane bilety rezerwacji opłaconych dziś;
 *   obłożenie       — nieanulowane bilety / aktywne miejsca sali, seanse zaczynające się dziś;
 *   top filmów      — nieanulowane bilety z rezerwacji opłaconych w ostatnich 7 dobach (dziś + 6).
 *
 * ZAKRES: obsługa zawsze tylko swoje kino (niezależnie od filtra), administrator
 * — wszystkie kina albo jedno wybrane. Liczymy go tu, nie w komponencie.
 */
final class SalesDashboardService
{
    public const TOP_MOVIES = 5;

    public const TOP_MOVIES_DAYS = 7;

    public const RECENT_LIMIT = 10;

    /** @return Collection<int, Cinema> kina w zakresie użytkownika (id, name, city, timezone) */
    public function cinemasFor(User $user, ?int $cinemaId = null): Collection
    {
        return Cinema::query()
            ->when(! $user->isAdmin(), fn ($query) => $query->whereKey((int) $user->cinema_id))
            ->when($user->isAdmin() && $cinemaId !== null, fn ($query) => $query->whereKey($cinemaId))
            ->orderBy('city')
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'timezone']);
    }

    /**
     * @param  Collection<int, Cinema>  $cinemas
     * @return array{gross: int, refunds: int, net: int, tickets: int, bookings: int, pending_refunds: int}
     */
    public function today(Collection $cinemas, CarbonImmutable $now): array
    {
        if ($cinemas->isEmpty()) {
            return ['gross' => 0, 'refunds' => 0, 'net' => 0, 'tickets' => 0, 'bookings' => 0, 'pending_refunds' => 0];
        }

        $sales = $this->bookingsInScope($cinemas)
            ->whereIn('bookings.status', [BookingStatus::Paid->value, BookingStatus::Refunded->value])
            ->where(fn (Builder $query) => $this->withinLocalDays($query, 'bookings.paid_at', $cinemas, $now, 1))
            ->selectRaw('count(*) AS bookings, coalesce(sum(bookings.total_amount), 0) AS gross')
            ->first();

        $tickets = $this->bookingsInScope($cinemas)
            ->join('tickets', 'tickets.booking_id', '=', 'bookings.id')
            ->where('tickets.status', '!=', TicketStatus::Cancelled->value)
            ->where(fn (Builder $query) => $this->withinLocalDays($query, 'bookings.paid_at', $cinemas, $now, 1))
            ->count();

        $refunds = (int) $this->bookingsInScope($cinemas)
            ->where('bookings.status', BookingStatus::Refunded->value)
            ->where(fn (Builder $query) => $this->withinLocalDays($query, 'bookings.refund_completed_at', $cinemas, $now, 1))
            ->sum('bookings.total_amount');

        $pending = $this->bookingsInScope($cinemas)
            ->whereNotNull('bookings.refund_requested_at')
            ->whereNull('bookings.refund_completed_at')
            ->count();

        return [
            'gross' => (int) $sales->gross,
            'refunds' => $refunds,
            'net' => (int) $sales->gross - $refunds,
            'tickets' => $tickets,
            'bookings' => (int) $sales->bookings,
            'pending_refunds' => $pending,
        ];
    }

    /**
     * Seanse zaczynające się dziś (w strefie kina), bez odwołanych, z obłożeniem.
     *
     * @param  Collection<int, Cinema>  $cinemas
     * @return list<array{id: int, time: string, cinema: string, hall: string, movie: string, sold: int, capacity: int, percent: int}>
     */
    public function occupancyToday(Collection $cinemas, CarbonImmutable $now): array
    {
        if ($cinemas->isEmpty()) {
            return [];
        }

        $timezones = $cinemas->pluck('timezone', 'id');

        return DB::table('screenings')
            ->join('halls', 'halls.id', '=', 'screenings.hall_id')
            ->join('cinemas', 'cinemas.id', '=', 'halls.cinema_id')
            ->join('movies', 'movies.id', '=', 'screenings.movie_id')
            ->whereIn('halls.cinema_id', $cinemas->pluck('id'))
            ->where('screenings.status', '!=', ScreeningStatus::Cancelled->value)
            ->where(fn (Builder $query) => $this->withinLocalDays($query, 'screenings.starts_at', $cinemas, $now, 1))
            ->select(['screenings.id', 'screenings.starts_at', 'halls.cinema_id', 'cinemas.name AS cinema', 'halls.name AS hall', 'movies.title AS movie'])
            ->selectSub(fn (Builder $sub) => $sub->from('tickets')->whereColumn('tickets.screening_id', 'screenings.id')
                ->where('tickets.status', '!=', TicketStatus::Cancelled->value)->selectRaw('count(*)'), 'sold')
            ->selectSub(fn (Builder $sub) => $sub->from('seats')->whereColumn('seats.hall_id', 'screenings.hall_id')
                ->where('seats.is_active', true)->selectRaw('count(*)'), 'capacity')
            ->orderBy('screenings.starts_at')
            ->orderBy('cinemas.name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'time' => CarbonImmutable::parse($row->starts_at, 'UTC')->setTimezone($timezones[$row->cinema_id])->format('H:i'),
                'cinema' => $row->cinema,
                'hall' => $row->hall,
                'movie' => $row->movie,
                'sold' => (int) $row->sold,
                'capacity' => (int) $row->capacity,
                // Procent w dół: 99,6% to jeszcze nie komplet.
                'percent' => (int) $row->capacity === 0 ? 0 : intdiv((int) $row->sold * 100, (int) $row->capacity),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Cinema>  $cinemas
     * @return list<array{title: string, tickets: int, revenue: int}>
     */
    public function topMovies(Collection $cinemas, CarbonImmutable $now): array
    {
        if ($cinemas->isEmpty()) {
            return [];
        }

        return $this->bookingsInScope($cinemas)
            ->join('tickets', 'tickets.booking_id', '=', 'bookings.id')
            ->join('movies', 'movies.id', '=', 'screenings.movie_id')
            ->where('tickets.status', '!=', TicketStatus::Cancelled->value)
            ->where(fn (Builder $query) => $this->withinLocalDays($query, 'bookings.paid_at', $cinemas, $now, self::TOP_MOVIES_DAYS))
            ->groupBy('movies.id', 'movies.title')
            ->selectRaw('movies.title AS title, count(*) AS tickets, sum(tickets.price) AS revenue')
            ->orderByDesc('tickets')
            ->orderBy('movies.title')
            ->limit(self::TOP_MOVIES)
            ->get()
            ->map(fn (object $row): array => ['title' => $row->title, 'tickets' => (int) $row->tickets, 'revenue' => (int) $row->revenue])
            ->all();
    }

    /**
     * Ostatnio zmienione rezerwacje jako wpisy feedu — ten sam kształt co zdarzenie
     * sales.activity (SalesActivity::broadcastWith), więc widok ma jeden format.
     * Bez danych osobowych, jak zdarzenie.
     *
     * @param  Collection<int, Cinema>  $cinemas
     * @return list<array<string, mixed>>
     */
    public function recentActivity(Collection $cinemas, int $limit = self::RECENT_LIMIT): array
    {
        if ($cinemas->isEmpty()) {
            return [];
        }

        return $this->activityQuery($cinemas)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Booking $booking): array => $this->entry($booking, $booking->status, CarbonImmutable::parse($booking->updated_at)))
            ->all();
    }

    /**
     * Wpis feedu dla zdarzenia z WebSocketu. Z przeglądarki bierzemy WYŁĄCZNIE numer
     * rezerwacji i status (sprawdzone), resztę czytamy z bazy — w zakresie użytkownika.
     * Null: rezerwacja spoza zakresu, nieistniejąca albo zły format.
     *
     * @param  Collection<int, Cinema>  $cinemas
     * @return array<string, mixed>|null
     */
    public function activityEntry(Collection $cinemas, string $reference, string $status, CarbonImmutable $now): ?array
    {
        $parsed = BookingStatus::tryFrom($status);

        if ($cinemas->isEmpty() || $parsed === null || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $reference) !== 1) {
            return null;
        }

        $booking = $this->activityQuery($cinemas)->where('reference', $reference)->first();

        return $booking === null ? null : $this->entry($booking, $parsed, $now);
    }

    /** @return array<string, mixed> */
    private function entry(Booking $booking, BookingStatus $status, CarbonImmutable $at): array
    {
        return (new SalesActivity($booking, $status, $at))->broadcastWith();
    }

    /** @param Collection<int, Cinema> $cinemas */
    private function activityQuery(Collection $cinemas): EloquentBuilder
    {
        return Booking::query()
            ->with(['screening:id,hall_id,movie_id,starts_at', 'screening.movie:id,title', 'screening.hall:id,name,cinema_id', 'screening.hall.cinema:id,name,timezone'])
            ->withCount('seatLocks')
            ->whereIn('screening_id', DB::table('screenings')->join('halls', 'halls.id', '=', 'screenings.hall_id')
                ->whereIn('halls.cinema_id', $cinemas->pluck('id'))->select('screenings.id'));
    }

    /** @param Collection<int, Cinema> $cinemas */
    private function bookingsInScope(Collection $cinemas): Builder
    {
        return DB::table('bookings')
            ->join('screenings', 'screenings.id', '=', 'bookings.screening_id')
            ->join('halls', 'halls.id', '=', 'screenings.hall_id')
            ->whereIn('halls.cinema_id', $cinemas->pluck('id'));
    }

    /**
     * Warunek: kolumna w ostatnich $days dobach lokalnych (dziś i $days-1 wstecz)
     * kina, do którego należy wiersz. Jedna gałąź OR na strefę czasową.
     *
     * @param  Collection<int, Cinema>  $cinemas
     */
    private function withinLocalDays(Builder $query, string $column, Collection $cinemas, CarbonImmutable $now, int $days): void
    {
        foreach ($cinemas->groupBy('timezone') as $timezone => $group) {
            $localToday = $now->setTimezone((string) $timezone)->startOfDay();
            // Pułapka BN: do zapytania tylko UTC — kolumna timestamptz, format bez strefy.
            $from = $localToday->subDays($days - 1)->utc();
            $to = $localToday->addDay()->utc();

            $query->orWhere(fn (Builder $branch) => $branch
                ->whereIn('halls.cinema_id', $group->pluck('id'))
                ->where($column, '>=', $from)
                ->where($column, '<', $to));
        }
    }
}
