<?php

namespace App\Http\Resources\V1;

use App\Models\Booking;
use App\Support\Labels;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rezerwacja w historii zamówień klienta.
 *
 * CZEGO TU NIE MA I DLACZEGO:
 *
 *   stripe_payment_intent_id — wewnętrzna referencja do Stripe'a.
 *     Klientowi niepotrzebna, a ujawniona ułatwia rekonesans przed
 *     atakiem na integrację płatności.
 *
 *   user_id — odbiorca i tak jest właścicielem (pilnuje BookingPolicy),
 *     więc pole niosłoby wyłącznie informację o numeracji kont.
 *
 *   cancelled_by_user_id — kto anulował, to sprawa wewnętrzna kina.
 *     Klient dostaje sam powód, jeśli anulowanie nastąpiło.
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // reference (ULID) jest kluczem trasy — to po nim klient
            // wchodzi w szczegóły, nie po sekwencyjnym id.
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => Labels::bookingStatus($this->status),
            'total' => Money::minor($this->total_amount, $this->currency)->toArray(),
            'tickets_count' => $this->whenCounted('tickets'),
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            // expires_at dotyczy rezerwacji oczekującej na płatność —
            // po tym czasie blokady wracają do puli (Etap 4).
            'expires_at' => $this->expires_at?->toIso8601String(),
            'cancellation' => $this->when(
                $this->cancelled_at !== null,
                fn (): array => [
                    'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                    'reason' => $this->cancellation_reason,
                ],
            ),
            'screening' => new ScreeningResource($this->whenLoaded('screening')),
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),
        ];
    }
}
