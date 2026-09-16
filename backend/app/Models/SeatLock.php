<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatLock extends Model
{
    use HasFactory;

    protected $fillable = [
        'screening_id',
        'seat_id',
        'session_id',
        'user_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Blokady faktycznie trzymajace miejsce: niezwolnione i niewygasle.
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('released_at')->where('expires_at', '>', now());
    }

    /**
     * Blokady wygasle, ale wciaz niezwolnione.
     *
     * UWAGA: takie wiersze NADAL zajmuja miejsce w indeksie
     * seat_locks_active_unique, bo jego predykat nie moze zawierac now().
     * Dlatego serwis blokujacy musi je zwolnic w tej samej transakcji,
     * zanim sprobuje wstawic wlasna blokade.
     */
    public function scopeExpired(Builder $query): void
    {
        $query->whereNull('released_at')->where('expires_at', '<=', now());
    }

    /**
     * JEDYNA kolejność blokowania wierszy seat_locks (Etap 7, blok J).
     *
     * Każde SELECT … FOR UPDATE na tej tabeli idzie przez ten scope. Dwie transakcje
     * blokujące te same wiersze w tej samej kolejności nie mogą się zakleszczyć:
     * druga czeka na pierwszą zamiast trzymać wiersz, na który czeka pierwsza.
     *
     * Wśród niezwolnionych blokad para (screening_id, seat_id) jest unikalna
     * (indeks seat_locks_active_unique), więc kolejność jest całkowita także
     * w porcji sprzątania, która obejmuje wiele seansów.
     */
    public function scopeInLockOrder(Builder $query): void
    {
        $query->orderBy('screening_id')->orderBy('seat_id');
    }

    public function isActive(): bool
    {
        return $this->released_at === null && $this->expires_at->isFuture();
    }
}
