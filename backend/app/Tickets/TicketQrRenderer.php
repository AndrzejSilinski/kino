<?php

declare(strict_types=1);

namespace App\Tickets;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;
use RuntimeException;

/**
 * Obraz kodu QR biletu, generowany po stronie serwera (wymóg 1.5 zadania).
 *
 * Jedyne miejsce w aplikacji, które zna bibliotekę endroid/qr-code — tak
 * jak StripePaymentGateway jest jedynym miejscem ze Stripe'em. Na zewnątrz
 * wychodzą tylko bajty PNG albo data URI, nigdy obiekty biblioteki.
 *
 * DECYZJE W PARAMETRACH:
 *   ErrorCorrectionLevel::High — kod znosi utratę ok. 30% modułów, a logo
 *     na środku zasłania ich ok. 4%. Niższy poziom z logo bywa nieczytelny.
 *   logo szerokości 20% boku + punchout — pod logo nie ma modułów, więc
 *     skaner nie widzi "szumu", a zapas na zabrudzenie wydruku zostaje.
 *   ISO-8859-1 zamiast UTF-8 — token to czyste ASCII. Przy UTF-8 koder
 *     dokleja nagłówek ECI, który część starszych skanerów źle obsługuje.
 *   margines 10% boku — norma QR wymaga ciszy o szerokości 4 modułów
 *     wokół kodu; przy 41 modułach (wersja 6) na 480 px to ok. 47 px.
 *   RoundBlockSizeMode::Margin — każdy moduł ma całkowitą liczbę pikseli
 *     (ostre krawędzie na wydruku), a reszta trafia do marginesu.
 */
final class TicketQrRenderer
{
    /** Szerokość logo jako ułamek boku kodu. */
    private const LOGO_WIDTH_RATIO = 0.2;

    /** Margines jako ułamek boku kodu. */
    private const MARGIN_RATIO = 0.1;

    public function __construct(
        private readonly TicketTokenSigner $signer,
        private readonly int $size,
        private readonly string $logoPath,
    ) {
        // Brak pliku wykrywamy od razu, a nie w workerze przy trzeciej
        // próbie wysłania maila — to błąd wdrożenia, nie chwilowa awaria.
        if (! is_file($logoPath)) {
            throw new RuntimeException('Brak pliku logo do kodu QR: '.$logoPath);
        }
    }

    /** Surowe bajty PNG — do odpowiedzi HTTP z obrazem (Blok F). */
    public function png(string $ticketCode): string
    {
        return $this->build($ticketCode)->getString();
    }

    /** data:image/png;base64,... — do osadzenia w HTML/PDF bez odwołań sieciowych (Blok C). */
    public function dataUri(string $ticketCode): string
    {
        return $this->build($ticketCode)->getDataUri();
    }

    private function build(string $ticketCode): ResultInterface
    {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $this->signer->sign($ticketCode),
            encoding: new Encoding('ISO-8859-1'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $this->size,
            margin: (int) round($this->size * self::MARGIN_RATIO),
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            logoPath: $this->logoPath,
            logoResizeToWidth: (int) round($this->size * self::LOGO_WIDTH_RATIO),
            logoPunchoutBackground: true,
        );

        return $builder->build();
    }
}
