<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BookingStatus;
use App\Support\Labels;

/**
 * Anulowanie rezerwacji przez administratora, którego nie wolno wykonać (Etap 7, blok K).
 *
 * Jedna klasa z fabrykami (decyzja 81): każdy przypadek ma własny kod błędu i status.
 * 409 — żądanie poprawne, ale koliduje ze stanem rezerwacji (już anulowana, seans
 * się zaczął, ktoś wszedł na salę). 422 — zły powód anulowania.
 *
 * Kontekst NIE zawiera treści powodu: to notatka wewnętrzna kina, a kontekst
 * wyjątku trafia do odpowiedzi API i do logów.
 */
final class BookingCancellationException extends CinemaException
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 255;

    /** @param array<string, mixed> $details */
    private function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $errorCode,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function wrongStatus(BookingStatus $status): self
    {
        return new self(
            'Anulować można tylko rezerwację oczekującą na płatność albo opłaconą. Ta ma status: '.Labels::bookingStatus($status).'.',
            409,
            'BOOKING_NOT_CANCELLABLE',
            ['status' => $status->value],
        );
    }

    public static function screeningStarted(): self
    {
        return new self(
            'Seans już się rozpoczął — opłaconej rezerwacji nie można anulować ze zwrotem.',
            409,
            'BOOKING_SCREENING_STARTED',
        );
    }

    public static function ticketsUsed(int $used): self
    {
        return new self(
            "Część biletów została już wykorzystana przy wejściu na salę ({$used}). Takiej rezerwacji nie anulujemy ze zwrotem.",
            409,
            'BOOKING_TICKETS_USED',
            ['used_tickets' => $used],
        );
    }

    public static function invalidReason(): self
    {
        return new self(
            'Powód anulowania musi mieć od '.self::REASON_MIN.' do '.self::REASON_MAX.' znaków.',
            422,
            'CANCELLATION_REASON_INVALID',
            ['min' => self::REASON_MIN, 'max' => self::REASON_MAX],
        );
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->details;
    }
}
