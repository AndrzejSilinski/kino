<?php

declare(strict_types=1);

namespace App\Payments;

use App\Exceptions\InvalidWebhookSignatureException;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Exceptions\PaymentRejectedException;
use Carbon\CarbonImmutable;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\CurlClient;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Jedyne miejsce w aplikacji, które wie o istnieniu Stripe'a.
 *
 * Wszystkie wywołania idą przez call(), które tłumaczy wyjątki SDK na
 * nasze wyjątki domenowe. Dzięki temu z serwisów nigdy nie wyleci
 * ApiErrorException, tak jak z Etapu 2 nigdy nie wylatuje QueryException.
 */
final class StripePaymentGateway implements PaymentGateway
{
    private readonly StripeClient $stripe;

    /** @param array<string, mixed> $config sekcja payments.stripe */
    public function __construct(private readonly array $config)
    {
        $secret = (string) ($config['secret_key'] ?? '');

        if ($secret === '') {
            throw new RuntimeException('Brak STRIPE_SECRET_KEY w konfiguracji płatności.');
        }

        // Bezpiecznik na pomyłkę, która kosztuje prawdziwe pieniądze:
        // poza produkcją wolno używać wyłącznie kluczy testowych.
        if (! app()->isProduction() && ! str_starts_with($secret, 'sk_test_')) {
            throw new RuntimeException('Poza produkcją dozwolone są tylko testowe klucze Stripe (sk_test_...).');
        }

        // Limity czasu ustawia się na kliencie HTTP SDK, który jest globalny.
        // Domyślne 80 s to wieczność dla żądania, na które czeka klient
        // przy kasie albo Stripe czekający na odpowiedź webhooka.
        $curl = new CurlClient();
        $curl->setTimeout((int) $config['timeout_seconds']);
        $curl->setConnectTimeout((int) $config['connect_timeout_seconds']);
        ApiRequestor::setHttpClient($curl);

        $this->stripe = new StripeClient([
            'api_key' => $secret,
            'max_network_retries' => (int) $config['max_network_retries'],
        ]);
    }

    public function createIntent(
        int $amountMinor,
        string $currency,
        string $bookingReference,
        string $idempotencyKey,
    ): PaymentIntentData {
        return $this->call(fn (): PaymentIntentData => $this->toData(
            $this->stripe->paymentIntents->create([
                'amount' => $amountMinor,
                // Stripe chce kodu waluty małymi literami, my trzymamy PLN.
                'currency' => strtolower($currency),

                // Metody płatności włączone w Dashboardzie, dobierane przez
                // Stripe do kwoty, waluty i kraju klienta.
                'automatic_payment_methods' => ['enabled' => true],

                // SERCE ROZWIĄZANIA WYŚCIGU: karty tylko autoryzujemy,
                // pieniądze pobieramy dopiero po wystawieniu biletów.
                // Ustawienie per metoda, a nie globalne capture_method,
                // bo BLIK nie wspiera ręcznego pobrania — przy globalnym
                // ustawieniu Stripe w ogóle by go nie pokazał klientowi.
                'payment_method_options' => ['card' => ['capture_method' => 'manual']],

                // W metadanych TYLKO nasza referencja. Żadnych danych
                // osobowych: metadane widać w Dashboardzie i w webhookach.
                'metadata' => ['booking_reference' => $bookingReference],
            ], ['idempotency_key' => $idempotencyKey])
        ));
    }

    public function retrieveIntent(string $intentId): PaymentIntentData
    {
        return $this->call(fn (): PaymentIntentData => $this->toData(
            $this->stripe->paymentIntents->retrieve($intentId)
        ));
    }

    public function captureIntent(string $intentId, string $idempotencyKey): PaymentIntentData
    {
        return $this->call(fn (): PaymentIntentData => $this->toData(
            $this->stripe->paymentIntents->capture($intentId, [], ['idempotency_key' => $idempotencyKey])
        ));
    }

    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntentData
    {
        return $this->call(fn (): PaymentIntentData => $this->toData(
            $this->stripe->paymentIntents->cancel(
                $intentId,
                ['cancellation_reason' => 'abandoned'],
                ['idempotency_key' => $idempotencyKey],
            )
        ));
    }

    public function refundIntent(string $intentId, string $idempotencyKey): void
    {
        $this->call(fn () => $this->stripe->refunds->create(
            ['payment_intent' => $intentId],
            ['idempotency_key' => $idempotencyKey],
        ));
    }

    public function parseWebhook(string $payload, string $signatureHeader): WebhookEventData
    {
        try {
            $event = Webhook::constructEvent(
                $payload,
                $signatureHeader,
                (string) $this->config['webhook_secret'],
                (int) $this->config['webhook_tolerance'],
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            // Zły podpis, przeterminowany znacznik czasu albo zepsuty JSON.
            throw new InvalidWebhookSignatureException($e);
        }

        $object = $event->data->object ?? null;

        return new WebhookEventData(
            id: $event->id,
            type: $event->type,
            createdAt: CarbonImmutable::createFromTimestampUTC($event->created),
            intent: $object instanceof PaymentIntent ? $this->toData($object) : null,
            refund: $object instanceof Refund ? $this->toRefundData($object) : null,
        );
    }

    /**
     * Zwrot Stripe'a -> nasze DTO (Etap 10, blok C). payment_intent bywa identyfikatorem
     * albo rozwiniętym obiektem (expand) — bierzemy identyfikator w obu przypadkach.
     */
    private function toRefundData(Refund $refund): RefundData
    {
        $intent = $refund->payment_intent ?? null;

        return new RefundData(
            id: (string) $refund->id,
            paymentIntentId: $intent instanceof PaymentIntent ? (string) $intent->id : ($intent === null ? null : (string) $intent),
            failed: $refund->status === Refund::STATUS_FAILED,
            failureReason: $refund->failure_reason ?? null,
        );
    }

    /** Obiekt Stripe'a -> nasze DTO. Jedyne miejsce, gdzie znamy jego pola. */
    private function toData(PaymentIntent $intent): PaymentIntentData
    {
        return new PaymentIntentData(
            id: $intent->id,
            status: PaymentIntentStatus::fromProvider($intent->status),
            amountMinor: (int) $intent->amount,
            amountCapturableMinor: (int) $intent->amount_capturable,
            amountReceivedMinor: (int) $intent->amount_received,
            currency: strtoupper((string) $intent->currency),
            clientSecret: $intent->client_secret,
            bookingReference: $intent->metadata['booking_reference'] ?? null,
            // isset, a nie ?-> : StripeObject ostrzega o nieistniejącej
            // właściwości, a od stripe-php v21 pola błędu bywają nullem.
            lastErrorCode: isset($intent->last_payment_error->code)
                ? (string) $intent->last_payment_error->code
                : null,
        );
    }

    /**
     * Tłumaczy wyjątki SDK na wyjątki domenowe.
     *
     * Kolejność catch ma znaczenie: ApiConnectionException i RateLimit
     * dziedziczą po ApiErrorException, więc muszą być przechwycone wcześniej.
     *
     * @template T
     * @param  callable(): T  $operation
     * @return T
     */
    private function call(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ApiConnectionException|RateLimitException $e) {
            // Sieć albo chwilowy limit u dostawcy — warto ponowić.
            throw new PaymentProviderUnavailableException($e);
        } catch (AuthenticationException $e) {
            // Zły klucz to błąd NASZEJ konfiguracji, nie problem płatności.
            // Niech leci jako 500 i trafi do logu — klient nic tu nie poprawi.
            throw new RuntimeException('Stripe odrzucił klucz API.', 0, $e);
        } catch (ApiErrorException $e) {
            throw new PaymentRejectedException($e->getError()?->code, $e);
        }
    }
}
