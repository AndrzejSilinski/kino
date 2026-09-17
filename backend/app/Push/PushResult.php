<?php

declare(strict_types=1);

namespace App\Push;

/** Wynik wysyłki na jedno urządzenie (Etap 8, blok K). */
final readonly class PushResult
{
    public const SENT = 'sent';

    /** Token nieważny (UNREGISTERED albo odrzucony token) — urządzenie do usunięcia. */
    public const INVALID_TOKEN = 'invalid_token';

    /** Chwilowy problem po stronie FCM (429, 5xx, sieć) — warto ponowić. */
    public const RETRYABLE = 'retryable';

    /** Błąd, którego ponowienie nie naprawi (zła konfiguracja, 401/403). */
    public const FAILED = 'failed';

    private function __construct(
        public string $status,
        public ?string $errorCode = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function sent(): self
    {
        return new self(self::SENT);
    }

    public static function invalidToken(string $errorCode): self
    {
        return new self(self::INVALID_TOKEN, $errorCode);
    }

    public static function retryable(?string $errorCode, ?int $retryAfterSeconds = null): self
    {
        return new self(self::RETRYABLE, $errorCode, $retryAfterSeconds);
    }

    public static function failed(?string $errorCode): self
    {
        return new self(self::FAILED, $errorCode);
    }
}
