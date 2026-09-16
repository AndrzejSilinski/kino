<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Bookings;

use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Movie;
use App\Models\Screening;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista rezerwacji z filtrami (Etap 7, blok I; wymóg 2.3).
 *
 * ZAKRES DANYCH liczymy na serwerze przy każdym renderze z zalogowanego
 * użytkownika, a nie z filtrów: obsługa kina zawsze widzi tylko swoje kino,
 * nawet gdy ktoś podmieni ?kino=… w adresie albo właściwość w żądaniu Livewire.
 *
 * DZIEŃ SEANSU w strefie TEGO kina, w SQL: (starts_at AT TIME ZONE cinemas.timezone)::date.
 * Kina sieci mogą mieć różne strefy, więc jedna para granic UTC nie wystarcza.
 *
 * N+1: relacje ładowane z góry, liczba biletów przez withCount; preventLazyLoading
 * wywróci test przy każdym zapomnianym with().
 */
#[Title('Rezerwacje')]
final class BookingIndex extends Component
{
    use WithPagination;

    public const STATUS_LABELS = [
        'pending' => 'oczekuje na płatność',
        'paid' => 'opłacona',
        'cancelled' => 'anulowana',
        'expired' => 'wygasła',
        'refunded' => 'zwrócona',
    ];

    #[Url(as: 'kino', except: '')]
    public string $cinemaId = '';

    #[Url(as: 'data', except: '')]
    public string $date = '';

    #[Url(as: 'film', except: '')]
    public string $movieId = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    /** Numer rezerwacji (ULID) albo jego początek, min. 4 znaki. */
    #[Url(as: 'nr', except: '')]
    public string $reference = '';

    public function mount(): void
    {
        $this->authorize('viewAnyInPanel', Booking::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['cinemaId', 'date', 'movieId', 'status', 'reference'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['cinemaId', 'date', 'movieId', 'status', 'reference']);
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'livewire.admin.partials.pagination';
    }

    public function render(): View
    {
        $this->authorize('viewAnyInPanel', Booking::class);

        /** @var User $user */
        $user = auth()->user();
        $scopeCinemaId = $user->isAdmin() ? $this->intOrNull($this->cinemaId) : (int) $user->cinema_id;

        $bookings = Booking::query()
            ->with([
                'user:id,name,email',
                'screening:id,hall_id,movie_id,starts_at,status',
                'screening.movie:id,title',
                'screening.hall:id,name,cinema_id',
                'screening.hall.cinema:id,name,slug,timezone',
            ])
            ->withCount(['tickets as active_tickets_count' => fn (Builder $tickets) => $tickets->where('status', '!=', TicketStatus::Cancelled)])
            ->when($scopeCinemaId !== null, fn (Builder $query) => $query->whereIn(
                'screening_id',
                Screening::query()->select('screenings.id')->join('halls', 'halls.id', '=', 'screenings.hall_id')->where('halls.cinema_id', $scopeCinemaId),
            ))
            ->when($this->validDate() !== null, fn (Builder $query) => $query->whereIn(
                'screening_id',
                Screening::query()->select('screenings.id')
                    ->join('halls', 'halls.id', '=', 'screenings.hall_id')
                    ->join('cinemas', 'cinemas.id', '=', 'halls.cinema_id')
                    ->whereRaw('(screenings.starts_at AT TIME ZONE cinemas.timezone)::date = ?', [$this->validDate()]),
            ))
            ->when($this->intOrNull($this->movieId) !== null, fn (Builder $query) => $query->whereIn(
                'screening_id',
                Screening::query()->select('id')->where('movie_id', $this->intOrNull($this->movieId)),
            ))
            ->when(BookingStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->referencePrefix() !== null, fn (Builder $query) => $query->where('reference', 'like', $this->referencePrefix().'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.admin.bookings.index', [
            'bookings' => $bookings,
            'isAdmin' => $user->isAdmin(),
            'cinemas' => $user->isAdmin() ? Cinema::query()->orderBy('city')->orderBy('name')->get(['id', 'name', 'city']) : collect(),
            'movies' => Movie::query()->orderBy('title')->get(['id', 'title']),
            'statuses' => self::STATUS_LABELS,
        ]);
    }

    private function intOrNull(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : null;
    }

    private function validDate(): ?string
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $this->date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $this->date;
    }

    /** Numer ULID to wielkie litery i cyfry (Crockford base32); nic innego nie trafia do LIKE. */
    private function referencePrefix(): ?string
    {
        $prefix = strtoupper(trim($this->reference));

        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{4,26}\z/', $prefix) === 1 ? $prefix : null;
    }
}
