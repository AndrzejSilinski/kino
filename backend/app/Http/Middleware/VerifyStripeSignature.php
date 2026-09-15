<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\InvalidWebhookSignatureException;
use App\Payments\PaymentGateway;
use App\Payments\WebhookEventData;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Weryfikacja podpisu webhooka Stripe'a.
 *
 * Endpoint webhooka nie ma tokenu, sesji ani CSRF — trasa leży w api.php,
 * więc jest bezstanowa z definicji. JEDYNĄ barierą jest podpis, dlatego
 * sprawdzamy go w middleware: kontroler nie ma szansy wykonać się na
 * niezweryfikowanym żądaniu, nawet gdyby ktoś zapomniał o sprawdzeniu.
 *
 * Zweryfikowane zdarzenie wędruje dalej w atrybutach żądania — dokładnie
 * tak, jak ResolveBookingSession przekazuje identyfikator sesji zakupowej.
 */
class VerifyStripeSignature
{
    public const HEADER = 'Stripe-Signature';

    public const ATTRIBUTE = 'stripe_event';

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = (string) $request->header(self::HEADER, '');

        try {
            // getContent() to SUROWE bajty żądania. $request->all() albo
            // ponowne json_encode() dałyby inne bajty (kolejność kluczy,
            // spacje, escapowanie ukośników), a podpis liczy się z bajtów —
            // po takiej operacji każde zdarzenie byłoby "podrobione".
            $event = $this->gateway->parseWebhook($request->getContent(), $signature);
        } catch (InvalidWebhookSignatureException $e) {
            // CinemaException jest w dontReport(), więc ten wyjątek nie
            // trafiłby do logu. Dla zajętego miejsca to zaleta, ale próba
            // podszycia się pod Stripe'a to sygnał bezpieczeństwa i chcemy
            // ją widzieć. Logujemy BEZ treści żądania — payload mógłby
            // zawierać dane osobowe płacącego.
            Log::warning('Odrzucony webhook Stripe: nieprawidłowy podpis.', [
                'ip' => $request->ip(),
                'has_signature_header' => $signature !== '',
                'content_length' => strlen($request->getContent()),
            ]);

            throw $e;
        }

        $request->attributes->set(self::ATTRIBUTE, $event);

        return $next($request);
    }

    /** Odczyt zweryfikowanego zdarzenia w kontrolerze. */
    public static function eventFrom(Request $request): WebhookEventData
    {
        $event = $request->attributes->get(self::ATTRIBUTE);

        if (! $event instanceof WebhookEventData) {
            throw new LogicException('Trasa webhooka bez middleware VerifyStripeSignature.');
        }

        return $event;
    }
}
