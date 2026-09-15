<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveBookingSession;
use App\Http\Resources\V1\CheckoutResource;
use App\Models\Screening;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zamiana koszyka w rezerwację i utworzenie płatności.
 *
 * Kontroler nie przyjmuje ŻADNYCH danych w ciele żądania — ani kwoty,
 * ani listy miejsc. Miejsca wynikają z blokad przypisanych do sesji
 * zakupowej, a kwota z cennika seansu. Klient nie ma tu nic do
 * powiedzenia poza tym, kim jest (token) i czyj jest koszyk (nagłówek).
 *
 * Kod 201 przy pierwszym wywołaniu, 200 przy powtórzeniu — dzięki temu
 * frontend rozróżnia "utworzono płatność" od "masz już rozpoczętą",
 * mimo że w obu przypadkach dostaje identyczne dane.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request, Screening $screening): JsonResponse
    {
        $sessionId = (string) $request->attributes->get(ResolveBookingSession::ATTRIBUTE);

        [$booking, $intent] = $this->payments->startCheckout(
            $screening,
            $sessionId,
            $request->user(),
        );

        return (new CheckoutResource($booking, $intent))
            ->response()
            ->setStatusCode($booking->wasRecentlyCreated ? 201 : 200);
    }
}
