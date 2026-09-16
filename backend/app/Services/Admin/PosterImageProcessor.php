<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\InvalidPosterException;

/**
 * Plakat filmu: z wgranego JPG/PNG robi JPEG o ograniczonych wymiarach (Etap 7, blok F).
 *
 * DLACZEGO PRZEKODOWANIE, a nie zapis pliku 1:1:
 * - nowy plik nie niesie metadanych (EXIF z GPS, model aparatu) ani doklejonych
 *   za obrazem danych — zapisujemy tylko piksele;
 * - klient mobilny dostaje plik rzędu 100 KB zamiast 5 MB;
 * - na dysku lądują wyłącznie JPEG-i, niezależnie od tego, co przysłano.
 *
 * KOLEJNOŚĆ SPRAWDZEŃ ma znaczenie. getimagesize() czyta sam nagłówek, więc
 * wymiary znamy PRZED dekodowaniem. Plik PNG o rozmiarze kilkudziesięciu KB może
 * deklarować 20 000 × 20 000 px — rozpakowany zająłby ~1,6 GB RAM (tzw. bomba
 * dekompresyjna). Limit MAX_PIXELS odrzuca go, zanim GD zaalokuje pamięć:
 * ~4 bajty na piksel, 16 Mpx ≈ 64 MB przy memory_limit 128M.
 *
 * GD w obrazie cinema/php:dev nie obsługuje WebP ani AVIF (rozpoznanie F0),
 * dlatego przyjmujemy tylko JPEG i PNG, a zapisujemy JPEG.
 */
final class PosterImageProcessor
{
    public const MIN_WIDTH = 300;

    public const MIN_HEIGHT = 450;

    public const MAX_WIDTH = 800;

    public const MAX_HEIGHT = 1200;

    public const MAX_PIXELS = 16_000_000;

    private const JPEG_QUALITY = 85;

    /**
     * @param  string  $path  ścieżka do pliku na dysku (np. plik tymczasowy Livewire)
     * @return string bajty gotowego pliku JPEG
     *
     * @throws InvalidPosterException
     */
    public function toJpeg(string $path): string
    {
        $info = is_file($path) ? @getimagesize($path) : false;

        if ($info === false) {
            throw InvalidPosterException::unreadable();
        }

        [$width, $height, $type] = $info;

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw InvalidPosterException::unsupportedType();
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw InvalidPosterException::tooManyPixels($width, $height, intdiv(self::MAX_PIXELS, 1_000_000));
        }

        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
            throw InvalidPosterException::tooSmall($width, $height, self::MIN_WIDTH, self::MIN_HEIGHT);
        }

        $source = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);

        if ($source === false) {
            throw InvalidPosterException::unreadable();
        }

        // Tylko pomniejszamy: powiększenie małego obrazu dałoby rozmyty plakat większy na dysku.
        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        // Białe tło: przezroczyste piksele PNG w JPEG (bez kanału alfa) wyszłyby czarne.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        unset($source);

        ob_start();
        $written = imagejpeg($target, null, self::JPEG_QUALITY);
        $bytes = (string) ob_get_clean();
        unset($target);

        if (! $written || $bytes === '') {
            throw InvalidPosterException::unreadable();
        }

        return $bytes;
    }
}
