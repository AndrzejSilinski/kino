<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyStripeSignature;
use App\Models\Booking;
use App\Models\StripeWebhookEvent;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Odbiór zdarzeń Stripe'a. Kontroler przyjmuje i oddaje, decyduje serwis.
 *
 * KOLEJNOŚĆ JEST CELOWA: najpierw przetwarzamy zdarzenie, dopiero potem
 * zapisujemy je w dzienniku. Zapis na wejściu sprawiłby, że przerwane
 * przetwarzanie (np. nieudany capture) zostałoby przy ponowieniu uznane
 * za wykonane i nigdy by się nie dokończyło. Przy tej kolejności wyjątek
 * daje 500, Stripe ponawia, a idempotencję zapewniają zamek na wierszu
 * rezerwacji i UNIQUE na biletach.
 *
 * Odpowiadamy 2xx także na zdarzenia, których nie obsługujemy: każdy inny
 * kod Stripe traktuje jako nieudane dostarczenie i ponawia je godzinami.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request): JsonResponse
    {
        $event = VerifyStripeSignature::eventFrom($request);

        if (StripeWebhookEvent::query()->whereKey($event->id)->exists()) {
            return response()->json(['data' => ['received' => true, 'duplicate' => true]]);
        }

        $outcome = $this->payments->handleEvent($event);

        StripeWebhookEvent::create([
            'event_id' => $event->id,
            'type' => $event->type,
            'payment_intent_id' => $event->intent?->id,
            // Osobne zapytanie zamiast przekazywania identyfikatora przez
            // serwis: to pole jest wyłącznie audytowe i nie warto dla
            // niego komplikować sygnatury handleEvent().
            'booking_id' => $event->intent === null ? null : Booking::query()
                ->where('stripe_payment_intent_id', $event->intent->id)
                ->value('id'),
            'stripe_created_at' => $event->createdAt,
            'outcome' => $outcome,
            'processed_at' => CarbonImmutable::now(),
        ]);

        return response()->json([
            'data' => ['received' => true, 'duplicate' => false, 'outcome' => $outcome],
        ]);
    }
}
