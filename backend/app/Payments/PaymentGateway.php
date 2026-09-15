<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * Bramka płatności — granica między naszą domeną a dostawcą.
 *
 * KLUCZE IDEMPOTENCJI są parametrem, a nie szczegółem implementacji.
 * Powód: klucz musi być DETERMINISTYCZNY i wynikać z rezerwacji
 * ("booking:{ref}:create-intent"), a nie losowy. Losowy klucz nie chroni
 * przed ponowieniem po awarii, bo nowy proces wylosowałby nowy. O tym,
 * co jest ponowieniem czego, wie warstwa domenowa — więc to ona podaje
 * klucz, a bramka tylko go przekazuje.
 *
 * Metody rzucają wyjątki domenowe (402 / 503), nigdy wyjątków dostawcy.
 */
interface PaymentGateway
{
    /** Tworzy płatność na kwotę policzoną po stronie serwera. */
    public function createIntent(
        int $amountMinor,
        string $currency,
        string $bookingReference,
        string $idempotencyKey,
    ): PaymentIntentData;

    /** Odczyt bieżącego stanu płatności u dostawcy. */
    public function retrieveIntent(string $intentId): PaymentIntentData;

    /** Pobiera zablokowane wcześniej pieniądze. Wołane PO wystawieniu biletów. */
    public function captureIntent(string $intentId, string $idempotencyKey): PaymentIntentData;

    /** Zwalnia autoryzację bez pobrania — klient nie zobaczy obciążenia. */
    public function cancelIntent(string $intentId, string $idempotencyKey): PaymentIntentData;

    /** Zwrot pieniędzy już pobranych (metody bez wsparcia dla capture, np. BLIK). */
    public function refundIntent(string $intentId, string $idempotencyKey): void;

    /**
     * Weryfikuje podpis i zamienia surowe żądanie w zdarzenie.
     *
     * $payload musi być SUROWYM ciałem żądania. Ponowne zakodowanie JSON-a
     * zmienia bajty, a podpis liczony jest z bajtów — po takiej operacji
     * każde zdarzenie byłoby odrzucone jako podrobione.
     */
    public function parseWebhook(string $payload, string $signatureHeader): WebhookEventData;
}
