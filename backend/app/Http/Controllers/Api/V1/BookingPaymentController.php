<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BookingResource;
use App\Models\Booking;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Gate;

/**
 * Płatność rezerwacji z perspektywy klienta (Etap 8, blok H1).
 *
 * Rozpoczęcie płatności to checkout (CheckoutController) — tu jest tylko rezygnacja.
 * Osobny kontroler zamiast metody w BookingController: tamten wyłącznie czyta,
 * ten zmienia stan i rozmawia z operatorem płatności.
 */
final class BookingPaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Rezygnacja z płatności: 200 z rezerwacją w stanie cancelled (albo expired, jeśli
     * scheduler zdążył wcześniej). 409 BOOKING_NOT_PAYABLE dla opłaconej lub zwróconej.
     */
    public function destroy(Booking $booking): BookingResource
    {
        Gate::authorize('abandonPayment', $booking);

        $booking = $this->payments->abandonPayment($booking);

        return new BookingResource($booking->loadCount('tickets'));
    }
}
