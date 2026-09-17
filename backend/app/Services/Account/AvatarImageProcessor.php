<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Exceptions\InvalidAvatarException;

/**
 * Avatar: z wgranego JPG/PNG robi kwadratowy JPEG 256 × 256 (Etap 8, blok I).
 *
 * Ten sam wzór co plakat (PosterImageProcessor, Etap 7): przekodowanie zamiast zapisu
 * pliku 1:1. Dla avatara to jeszcze ważniejsze — zdjęcie z telefonu niesie w EXIF
 * współrzędne GPS, czyli często adres domowy klienta; zapisujemy tylko piksele.
 * Wymiary z nagłówka (getimagesize) sprawdzamy PRZED dekodowaniem — bomba
 * dekompresyjna odpada, zanim GD zaalokuje pamięć.
 *
 * Kwadrat z wycięciem środka (bez rozciągania): avatar wyświetla się w kółku,
 * a zniekształcona twarz wygląda gorzej niż przycięte brzegi.
 */
final class AvatarImageProcessor
{
    public const SIZE = 256;

    public const MIN_SIZE = 128;

    public const MAX_PIXELS = 16_000_000;

    private const JPEG_QUALITY = 85;

    /**
     * @return string bajty gotowego pliku JPEG
     *
     * @throws InvalidAvatarException
     */
    public function toJpeg(string $path): string
    {
        $info = is_file($path) ? @getimagesize($path) : false;

        if ($info === false) {
            throw InvalidAvatarException::unreadable();
        }

        [$width, $height, $type] = $info;

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw InvalidAvatarException::unsupportedType();
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw InvalidAvatarException::tooManyPixels($width, $height, intdiv(self::MAX_PIXELS, 1_000_000));
        }

        if ($width < self::MIN_SIZE || $height < self::MIN_SIZE) {
            throw InvalidAvatarException::tooSmall($width, $height, self::MIN_SIZE);
        }

        $source = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);

        if ($source === false) {
            throw InvalidAvatarException::unreadable();
        }

        // Największy kwadrat ze środka obrazu.
        $side = min($width, $height);
        $sourceX = intdiv($width - $side, 2);
        $sourceY = intdiv($height - $side, 2);

        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        // Białe tło: przezroczystość PNG w JPEG (bez kanału alfa) wyszłaby czarna.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, $sourceX, $sourceY, self::SIZE, self::SIZE, $side, $side);
        unset($source);

        ob_start();
        $written = imagejpeg($target, null, self::JPEG_QUALITY);
        $bytes = (string) ob_get_clean();
        unset($target);

        if (! $written || $bytes === '') {
            throw InvalidAvatarException::unreadable();
        }

        return $bytes;
    }
}
