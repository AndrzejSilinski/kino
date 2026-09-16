<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Plik plakatu, którego nie da się bezpiecznie przetworzyć (Etap 7, blok F).
 *
 * 422, bo problem leży w przesłanym pliku, a nie w stanie systemu. Reguły
 * walidacji formularza (mimes, max, dimensions) łapią większość przypadków
 * wcześniej; ten wyjątek to druga linia w serwisie, który nie ufa rozszerzeniu
 * ani temu, co zadeklarowała przeglądarka — patrzy w nagłówek pliku.
 */
final class InvalidPosterException extends CinemaException
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
        return new self('Plakat musi być plikiem JPG albo PNG.', 'unsupported_type');
    }

    public static function tooSmall(int $width, int $height, int $minWidth, int $minHeight): self
    {
        return new self(
            "Plakat ma {$width} × {$height} px, a musi mieć co najmniej {$minWidth} × {$minHeight} px.",
            'too_small',
        );
    }

    public static function tooManyPixels(int $width, int $height, int $maxMegapixels): self
    {
        return new self(
            "Plakat ma {$width} × {$height} px — to więcej niż {$maxMegapixels} Mpx. Zmniejsz obraz przed wgraniem.",
            'too_many_pixels',
        );
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'POSTER_INVALID';
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
