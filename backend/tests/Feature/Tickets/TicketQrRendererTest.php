<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Tickets\TicketQrRenderer;
use App\Tickets\TicketTokenSigner;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

/**
 * Obraz kodu QR biletu.
 *
 * Najważniejszy jest pierwszy test: obraz z logo DEKODUJEMY z powrotem
 * i porównujemy z podpisanym tokenem. Samo "powstał plik PNG" nie mówi
 * nic o tym, czy bramka przy sali go odczyta — a logo na środku kodu to
 * dokładnie ten element, który potrafi zepsuć czytelność.
 *
 * Dekoder to zbarimg (biblioteka ZBar, instalowana w docker/php/Dockerfile):
 * niezależna od endroid implementacja, używana w realnych skanerach.
 *
 * Bez bazy danych: renderer jej nie dotyka, więc test nie używa
 * RefreshDatabase.
 */
final class TicketQrRendererTest extends TestCase
{
    private const CODE = '3f2b8c1e-4d5a-4b6c-8d7e-9f0a1b2c3d4e';

    private const ZBARIMG = '/usr/bin/zbarimg';

    public function test_kod_z_logo_da_sie_odczytac_i_zawiera_podpisany_token(): void
    {
        // Brak narzędzia to błąd obrazu Dockera, a nie powód do pominięcia
        // testu — pominięty test w CI przepuściłby nieczytelne bilety.
        $this->assertFileExists(self::ZBARIMG, 'Brak zbarimg w obrazie PHP (docker/php/Dockerfile).');

        $path = sys_get_temp_dir().'/ticket-qr-'.bin2hex(random_bytes(6)).'.png';
        file_put_contents($path, app(TicketQrRenderer::class)->png(self::CODE));

        try {
            $result = Process::run([self::ZBARIMG, '--raw', '--quiet', $path]);
        } finally {
            unlink($path);
        }

        $this->assertTrue($result->successful(), 'zbarimg nie odczytał kodu: '.$result->errorOutput());

        $decoded = rtrim($result->output(), "\n");
        $signer = app(TicketTokenSigner::class);

        $this->assertSame($signer->sign(self::CODE), $decoded);
        // Pełna pętla, tak jak przy wejściu na salę: obraz -> token -> bilet.
        $this->assertSame(self::CODE, $signer->verify($decoded));
    }

    public function test_obraz_jest_kwadratowym_png_nie_mniejszym_niz_zadany_bok(): void
    {
        $png = app(TicketQrRenderer::class)->png(self::CODE);
        $info = getimagesizefromstring($png);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertNotFalse($info);
        $this->assertSame($info[0], $info[1]);
        $this->assertGreaterThanOrEqual((int) config('tickets.qr.size'), $info[0]);
    }

    public function test_data_uri_zawiera_ten_sam_obraz_co_png(): void
    {
        $renderer = app(TicketQrRenderer::class);
        $prefix = 'data:image/png;base64,';
        $uri = $renderer->dataUri(self::CODE);

        $this->assertStringStartsWith($prefix, $uri);
        $this->assertSame($renderer->png(self::CODE), base64_decode(substr($uri, strlen($prefix)), true));
    }

    public function test_brak_pliku_logo_zatrzymuje_renderer_od_razu(): void
    {
        $this->expectException(RuntimeException::class);

        new TicketQrRenderer(app(TicketTokenSigner::class), 480, '/nie/ma/takiego/logo.png');
    }
}
