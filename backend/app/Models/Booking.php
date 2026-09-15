<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'screening_id',
        'total_amount',
        'currency',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'total_amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * Publiczny numer zamowienia nadawany automatycznie przy tworzeniu.
     * ULID jest sortowalny chronologicznie i czytelny dla supportu.
     */
    protected static function booted(): void
    {
        static::creating(function (self $booking): void {
            $booking->reference ??= (string) Str::ulid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function seatLocks(): HasMany
    {
        return $this->hasMany(SeatLock::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * URL-e i maile uzywaja numeru zamowienia, nie id.
     */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }
}
