<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Models\Booking;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Arr;

/**
 * PDF ze wszystkimi biletami rezerwacji (wymóg 1.5 zadania).
 *
 * Jedyne miejsce w aplikacji, które zna dompdf — tak jak TicketQrRenderer
 * jest jedynym, które zna endroid/qr-code.
 *
 * Teksty (daty w strefie kina, ceny, etykiety) przygotowuje
 * BookingTicketsPresenter, wspólny z mailem potwierdzającym. Ta klasa
 * dokłada tylko to, co istnieje wyłącznie w PDF: logo i obrazy kodów QR.
 *
 * BEZPIECZEŃSTWO DOMPDF (ustawione jawnie, nie z wartości domyślnych):
 *   isRemoteEnabled=false     — żadnych pobrań z sieci (ochrona przed SSRF);
 *                               obrazy trafiają do HTML-u jako data URI
 *   isJavascriptEnabled=false — domyślnie dompdf pozwala na JavaScript w PDF
 *   isPhpEnabled=false        — żadnego wykonywania PHP z treści HTML
 *   chroot                    — zostaje domyślny (katalog samego dompdf):
 *                               nie czytamy żadnych plików lokalnych
 */
final class TicketPdfRenderer
{
    public function __construct(
        private readonly BookingTicketsPresenter $presenter,
        private readonly TicketQrRenderer $qr,
        private readonly ViewFactory $views,
        private readonly string $logoPath,
        private readonly string $fontCacheDir,
    ) {}

    /** Bajty gotowego PDF-a: jeden bilet na stronę A4. */
    public function render(Booking $booking): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->loadHtml($this->html($booking), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->addInfo('Title', 'Bilety - rezerwacja '.$booking->reference);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** HTML biletów. Publiczne, bo to na nim testujemy treść. */
    public function html(Booking $booking): string
    {
        $data = $this->presenter->present($booking);
        $data['logo'] = 'data:image/png;base64,'.base64_encode((string) file_get_contents($this->logoPath));

        // Kod biletu zamieniamy na obraz QR i USUWAMY z danych widoku,
        // żeby nie dało się go przypadkiem wypisać jako tekst (decyzja 68).
        $data['tickets'] = array_map(
            fn (array $ticket): array => Arr::except($ticket, 'code') + ['qr' => $this->qr->dataUri($ticket['code'])],
            $data['tickets'],
        );

        return $this->views->make('pdf.tickets', $data)->render();
    }

    private function options(): Options
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsPhpEnabled(false);
        // Czcionki dołączone do dompdf (DejaVu) czyta z vendor/, a pamięć
        // podręczną ewentualnych własnych czcionek trzyma w storage/,
        // bo vendor/ nie jest zapisywalny dla użytkownika www-data.
        $options->setFontCache($this->fontCacheDir);
        $options->setDefaultFont('DejaVu Sans');

        return $options;
    }
}
