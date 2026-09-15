<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Payments\PaymentGateway;
use App\Payments\PaymentIntentData;
use App\Payments\PaymentIntentStatus;
use App\Payments\StripePaymentGateway;
use App\Payments\WebhookEventData;

/**
 * Bramka płatności na potrzeby testów.
 *
 * Zapisuje wywołania zamiast wysyłać żądania, dzięki czemu test może
 * sprawdzić NIE TYLKO efekt w bazie, ale też czy pieniądze zostały
 * pobrane. W scenariuszu wyścigu to jest właśnie ta asercja, o którą
 * chodzi: capture NIE został wywołany, a cancel tak.
 *
 * parseWebhook celowo deleguje do prawdziwego adaptera: podpis to
 * kryptografia i test ma sprawdzać ją, a nie naszą atrapę.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{operation: string, intent: string, key: string}> */
    public array $calls = [];

    /** @var array<string, PaymentIntentData> */
    private array $intents = [];

    public function createIntent(
        int $amountMinor,
        string $currency,
        string $bookingReference,
        string $idempotencyKey,
    ): PaymentIntentData {
        // Ten sam klucz idempotencji zwraca ten sam identyfikator
        // płatności — tak samo jak prawdziwy Stripe.
        $id = 'pi_fake_'.substr(md5($idempotencyKey), 0, 16);
        $this->record('create', $id, $idempotencyKey);

        return $this->intents[$id] = new PaymentIntentData(
            id: $id,
            status: PaymentIntentStatus::RequiresPaymentMethod,
            amountMinor: $amountMinor,
            amountCapturableMinor: 0,
            amountReceivedMinor: 0,
            currency: strtoupper($currency),
            clientSecret: $id.'_secret_test',
            bookingReference: $bookingReference,
        );
    }

    public function retrieveIntent(string $intentId): PaymentIntentData
    {
        return $this->intents[$intentId] ?? new PaymentIntentData(
            id: $intentId,
            status: PaymentIntentStatus::RequiresCapture,
            amountMinor: 0,
            amountCapturableMinor: 0,
            amountReceivedMinor: 0,
            currency: 'PLN',
        );
    }

    public function captureIntent(string $intentId, string $idempotencyKey): PaymentIntentData
    {
        $this->record('capture', $intentId, $idempotencyKey);
        $intent = $this->retrieveIntent($intentId);

        return $this->intents[$intentId] = new PaymentIntentData(
            id: $intentId,
            status: PaymentIntentStatus::Succeeded,
            amountMinor: $intent->amountMinor,
            amountCapturableMinor: 0,
            amountReceivedMinor: $intent->amountMinor,
            currency: $intent->currency,
            bookingReference: $intent->bookingReference,
        );
    }

    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntentData
    {
        $this->record('cancel', $intentId, $idempotencyKey);
        $intent = $this->retrieveIntent($intentId);

        return $this->intents[$intentId] = new PaymentIntentData(
            id: $intentId,
            status: PaymentIntentStatus::Canceled,
            amountMinor: $intent->amountMinor,
            amountCapturableMinor: 0,
            amountReceivedMinor: 0,
            currency: $intent->currency,
            bookingReference: $intent->bookingReference,
        );
    }

    public function refundIntent(string $intentId, string $idempotencyKey): void
    {
        $this->record('refund', $intentId, $idempotencyKey);
    }

    /** Podpis sprawdza PRAWDZIWY adapter — atrapa nie udaje kryptografii. */
    public function parseWebhook(string $payload, string $signatureHeader): WebhookEventData
    {
        return (new StripePaymentGateway(config('payments.stripe')))
            ->parseWebhook($payload, $signatureHeader);
    }

    /** @return list<string> nazwy operacji w kolejności wywołań */
    public function operations(): array
    {
        return array_column($this->calls, 'operation');
    }

    private function record(string $operation, string $intent, string $key): void
    {
        $this->calls[] = ['operation' => $operation, 'intent' => $intent, 'key' => $key];
    }
}
