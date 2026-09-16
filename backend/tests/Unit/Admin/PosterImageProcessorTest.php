<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Exceptions\InvalidPosterException;
use App\Services\Admin\PosterImageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Przetwarzanie plakatu (Etap 7, blok F): typy, wymiary, bomba dekompresyjna, metadane.
 * Test jednostkowy bez Laravela — GD i pliki tymczasowe systemu.
 */
final class PosterImageProcessorTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'poster');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (InvalidPosterException $e) {
            return $e->context()['reason'];
        }

        $this->fail('Oczekiwano InvalidPosterException.');
    }

    public function test_large_jpeg_is_scaled_down_to_fit_the_box_keeping_proportions(): void
    {
        $bytes = (new PosterImageProcessor)->toJpeg($this->file($this->jpeg(2000, 3000)));

        $info = getimagesizefromstring($bytes);
        $this->assertSame([800, 1200, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    }

    public function test_small_enough_image_is_not_upscaled(): void
    {
        $bytes = (new PosterImageProcessor)->toJpeg($this->file($this->jpeg(400, 600)));

        $this->assertSame([400, 600], array_slice(getimagesizefromstring($bytes), 0, 2));
    }

    public function test_transparent_png_becomes_jpeg_on_white_background(): void
    {
        $image = imagecreatetruecolor(300, 450);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $bytes = (new PosterImageProcessor)->toJpeg($this->file($png));

        $result = imagecreatefromstring($bytes);
        $rgb = imagecolorsforindex($result, imagecolorat($result, 150, 200));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($bytes)[2]);
        $this->assertGreaterThan(245, min($rgb['red'], $rgb['green'], $rgb['blue']), 'Przezroczystość ma być biała, nie czarna.');
    }

    public function test_metadata_and_appended_bytes_are_not_copied(): void
    {
        $jpeg = $this->jpeg(400, 600);
        // Segment APP1 "Exif" zaraz po SOI (FF D8) i dane doklejone za końcem obrazu.
        $exif = "Exif\0\0GPS-SECRET-51.1079,17.0385";
        $withMetadata = "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2).'<?php echo 1; ?>';

        $bytes = (new PosterImageProcessor)->toJpeg($this->file($withMetadata));

        $this->assertStringContainsString('GPS-SECRET', $withMetadata);
        $this->assertStringNotContainsString('GPS-SECRET', $bytes);
        $this->assertStringNotContainsString('<?php', $bytes);
    }

    public function test_decompression_bomb_is_rejected_from_header_before_decoding(): void
    {
        // Sam nagłówek PNG deklarujący 20 000 × 20 000 px, bez danych obrazu.
        // Gdyby GD zaczęło dekodować, test zakończyłby się błędem pamięci albo
        // "unreadable" — oczekujemy odrzucenia z samego nagłówka.
        $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        $before = memory_get_peak_usage(true);
        $this->assertSame('too_many_pixels', $this->reason(fn () => (new PosterImageProcessor)->toJpeg($this->file($png))));
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_too_small_image_is_rejected(): void
    {
        $this->assertSame('too_small', $this->reason(fn () => (new PosterImageProcessor)->toJpeg($this->file($this->jpeg(299, 600)))));
        $this->assertSame('too_small', $this->reason(fn () => (new PosterImageProcessor)->toJpeg($this->file($this->jpeg(400, 449)))));
    }

    public function test_other_types_and_fake_images_are_rejected(): void
    {
        $gif = imagecreatetruecolor(400, 600);
        ob_start();
        imagegif($gif);
        $gifBytes = (string) ob_get_clean();

        $this->assertSame('unsupported_type', $this->reason(fn () => (new PosterImageProcessor)->toJpeg($this->file($gifBytes))));
        $this->assertSame('unreadable', $this->reason(fn () => (new PosterImageProcessor)->toJpeg($this->file('<?php echo "to nie jest obraz"; ?>'))));
        $this->assertSame('unreadable', $this->reason(fn () => (new PosterImageProcessor)->toJpeg('/nie/ma/takiego/pliku.jpg')));
    }

    public function test_truncated_jpeg_with_valid_header_is_rejected(): void
    {
        // Nagłówek i wymiary poprawne, dane obrazu ucięte po 200 bajtach.
        $broken = substr($this->jpeg(400, 600), 0, 200);

        $this->assertContains($this->reason(fn () => (new PosterImageProcessor)->toJpeg($this->file($broken))), ['unreadable']);
    }
}
