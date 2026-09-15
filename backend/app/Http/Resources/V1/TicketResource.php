<?php

namespace App\Http\Resources\V1;

use App\Models\Ticket;
use App\Support\Labels;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pojedynczy bilet w szczegółach rezerwacji.
 *
 * CZEGO TU NIE MA: kodu biletu (decyzja 60). Kod działa jak przepustka —
 * kto go ma, ten może wygenerować kod QR. Aplikacje dostają zamiast niego
 * qr_url: adres obrazu QR generowanego na serwerze (wymóg 1.5 zadania),
 * chroniony tokenem i BookingPolicy.
 *
 * qr_url pojawia się tylko wtedy, gdy bilet ma ustawioną relację booking
 * (BookingController::show robi to bez dodatkowego zapytania). Sięgnięcie
 * po niezaładowaną relację rzuciłoby wyjątkiem preventLazyLoading.
 *
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price' => Money::minor($this->price)->toArray(),
            'status' => $this->status->value,
            'status_label' => Labels::ticketStatus($this->status),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'qr_url' => $this->relationLoaded('booking')
                ? route('api.bookings.tickets.qr', ['booking' => $this->booking, 'ticket' => $this->resource])
                : null,
            'seat' => $this->whenLoaded('seat', fn (): array => [
                'id' => $this->seat->id,
                'row' => $this->seat->row_label,
                'number' => $this->seat->seat_number,
                'label' => $this->seat->label,
                'type' => $this->seat->type->value,
            ]),
        ];
    }
}
