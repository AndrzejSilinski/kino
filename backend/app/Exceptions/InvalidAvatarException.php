<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Wgrany avatar nie nadaje się do zapisu (Etap 8, blok I).
 *
 * Osobny wyjątek od InvalidPosterException, bo inne są progi i komunikaty, a kod
 * AVATAR_INVALID pozwala SPA pokazać błąd przy polu avatara. context.reason
 * rozróżnia przyczyny dla klienta, który chce własnego tekstu.
 */
final class InvalidAvatarException extends CinemaException
{
    private function __construct(
        string $message,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function unreadable(): self
    {
        return new self('Nie udało się odczytać obrazu. Wgraj plik JPG albo PNG.', 'unreadable');
    }

    public static function unsupportedType(): self
    {
        return new self('Avatar musi być plikiem JPG albo PNG.', 'unsupported_type');
    }

    public static function tooSmall(int $width, int $height, int $min): self
    {
        return new self("Obraz ma {$width} × {$height} px, a musi mieć co najmniej {$min} × {$min} px.", 'too_small');
    }

    public static function tooManyPixels(int $width, int $height, int $maxMegapixels): self
    {
        return new self("Obraz ma {$width} × {$height} px — to więcej niż {$maxMegapixels} Mpx. Zmniejsz go przed wgraniem.", 'too_many_pixels');
    }

    public static function storageFailed(): self
    {
        return new self('Nie udało się zapisać avatara. Spróbuj ponownie za chwilę.', 'storage_failed');
    }

    public function status(): int
    {
        return $this->reason === 'storage_failed' ? 503 : 422;
    }

    public function errorCode(): string
    {
        return 'AVATAR_INVALID';
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
