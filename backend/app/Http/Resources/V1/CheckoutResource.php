<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Booking;
use App\Payments\PaymentIntentData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Odpowiedź na checkout: rezerwacja + dane potrzebne do zapłaty.
 *
 * client_secret to jedyna wartość, którą klient dostaje od Stripe'a —
 * Payment Element w Vue i PaymentSheet we Flutterze przyjmują dokładnie
 * ten sam ciąg. Klucz publiczny dokładamy tu zamiast zaszywać go
 * w dwóch aplikacjach: podmiana konta Stripe nie wymaga wtedy wydania
 * nowej wersji mobilnej.
 *
 * Timer podajemy parą expires_at + expires_in_seconds, zgodnie
 * z kontraktem API: pierwsze do wyświetlenia, drugie do odliczania bez
 * polegania na zegarze urządzenia, który bywa przestawiony.
 */
class CheckoutResource extends JsonResource
{
    public function __construct(
        private readonly Booking $bookingModel,
        private readonly PaymentIntentData $intent,
    ) {
        parent::__construct($bookingModel);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'booking' => new BookingResource($this->bookingModel),
            'payment' => [
                'provider' => 'stripe',
                'publishable_key' => (string) config('payments.stripe.publishable_key'),
                'client_secret' => $this->intent->clientSecret,
                'status' => $this->intent->status->value,
                'expires_at' => $this->bookingModel->expires_at->toIso8601String(),
                'expires_in_seconds' => max(0, (int) CarbonImmutable::now()
                    ->diffInSeconds($this->bookingModel->expires_at, false)),
            ],
        ];
    }
}
