<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Pojedynczy bilet.
 *
 * KLUCZ TRASY TO ZWYKŁE id, a nie code (decyzje 60 i 79). Kod biletu
 * w adresie trafiałby do logów nginx, a z logów dałoby się wydrukować
 * bilet. Sekwencyjne id nie jest tu problemem, bo bilet występuje w adresie
 * wyłącznie w zakresie rezerwacji (/bookings/{ULID}/tickets/{id}),
 * a scopeBindings() odrzuca id spoza tej rezerwacji.
 */
class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'screening_id',
        'seat_id',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'price' => 'integer',
            'validated_at' => 'datetime',
        ];
    }

    /**
     * Kod biletu nadawany automatycznie. W kodzie QR trafia podpisany
     * (TicketTokenSigner, format T1). UUID v4 = 122 losowe bity, nie do
     * odgadnięcia i nie zdradza czasu zakupu.
     */
    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            $ticket->code ??= (string) Str::uuid();
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }
}
