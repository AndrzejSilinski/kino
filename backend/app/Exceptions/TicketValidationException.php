<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Screening;
use Carbon\CarbonInterface;

/**
 * Odmowa wpuszczenia na salę przy skanowaniu biletu (decyzje 52 i 81).
 *
 * Jedna klasa z metodami fabrycznymi zamiast sześciu osobnych: to jeden
 * obszar (bramka przy sali) i jeden kształt odpowiedzi, a różnią się tylko
 * kodem HTTP, kodem maszynowym i komunikatem dla obsługi.
 *
 * Konstruktor jest prywatny — wyjątek powstaje wyłącznie przez fabryki,
 * więc nie da się rzucić kombinacji kodu i statusu spoza listy.
 *
 * Komunikaty i kontekst trafiają do aplikacji obsługi kina, nie do klienta.
 * Kontekst nie zawiera danych osobowych ani kodu biletu.
 */
final class TicketValidationException extends CinemaException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $machineCode,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** Kod QR nie jest naszym biletem: zły format, wersja albo podpis. */
    public static function invalidToken(): self
    {
        return new self('Ten kod QR nie jest biletem naszego kina.', 422, 'TICKET_TOKEN_INVALID');
    }

    /** Podpis poprawny, ale biletu nie ma w bazie. */
    public static function notFound(): self
    {
        return new self('Nie znaleziono biletu.', 404, 'TICKET_NOT_FOUND');
    }

    /** Bilet na inny seans. Kontekst pomaga obsłudze pokierować widza. */
    public static function wrongScreening(Screening $ticketScreening): self
    {
        $timezone = $ticketScreening->hall->cinema->timezone;
        $startsAt = $ticketScreening->starts_at->copy()->setTimezone($timezone);

        return new self(
            'Bilet jest na inny seans: '.$ticketScreening->movie->title.', '.$ticketScreening->hall->name.', godz. '.$startsAt->format('H:i').'.',
            409,
            'TICKET_WRONG_SCREENING',
            ['ticket_screening' => [
                'id' => $ticketScreening->id,
                'movie_title' => $ticketScreening->movie->title,
                'cinema' => $ticketScreening->hall->cinema->name,
                'hall' => $ticketScreening->hall->name,
                'starts_at' => $startsAt->toIso8601String(),
            ]],
        );
    }

    public static function cancelled(): self
    {
        return new self('Bilet został anulowany.', 409, 'TICKET_CANCELLED');
    }

    public static function alreadyUsed(CarbonInterface $validatedAt, string $timezone): self
    {
        $local = $validatedAt->copy()->setTimezone($timezone);

        return new self(
            'Bilet został już wykorzystany o godz. '.$local->format('H:i').'.',
            409,
            'TICKET_ALREADY_USED',
            ['validated_at' => $local->toIso8601String()],
        );
    }

    public static function notYetOpen(CarbonInterface $opensAt, string $timezone): self
    {
        $local = $opensAt->copy()->setTimezone($timezone);

        return new self(
            'Wejście na ten seans otwiera się o godz. '.$local->format('H:i').'.',
            409,
            'TICKET_OUTSIDE_VALIDATION_WINDOW',
            ['opens_at' => $local->toIso8601String()],
        );
    }

    public static function windowClosed(CarbonInterface $endedAt, string $timezone): self
    {
        $local = $endedAt->copy()->setTimezone($timezone);

        return new self(
            'Seans zakończył się o godz. '.$local->format('H:i').'.',
            409,
            'TICKET_OUTSIDE_VALIDATION_WINDOW',
            ['ended_at' => $local->toIso8601String()],
        );
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->machineCode;
    }

    public function context(): array
    {
        return $this->details;
    }
}
