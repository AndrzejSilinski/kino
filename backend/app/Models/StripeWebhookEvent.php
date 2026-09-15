<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ślad przetworzonego zdarzenia webhooka Stripe'a.
 *
 * Model tylko do zapisu i odczytu audytowego — nie zawiera logiki.
 * Decyzje o tym, co zrobić ze zdarzeniem, podejmuje PaymentService.
 */
class StripeWebhookEvent extends Model
{
    /** Kluczem jest identyfikator ze Stripe'a, więc nie jest liczbą i nie rośnie. */
    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event_id',
        'type',
        'payment_intent_id',
        'booking_id',
        'stripe_created_at',
        'outcome',
        'processed_at',
    ];

    /**
     * immutable_datetime, a nie datetime — w tym projekcie czasy są
     * niemutowalne (CarbonImmutable), żeby przypadkowe ->addMinutes()
     * nie zmieniło wartości w modelu.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stripe_created_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
