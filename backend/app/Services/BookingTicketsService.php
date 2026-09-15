<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\TicketStatus;
use App\Exceptions\BookingTicketsUnavailableException;
use App\Exceptions\TicketValidationException;
use App\Models\Booking;
use App\Models\Ticket;
use App\Tickets\TicketPdfStore;
use App\Tickets\TicketQrRenderer;

/**
 * Bilety dla właściciela rezerwacji: PDF z historii zakupów i obraz QR
 * do wyświetlenia w aplikacji (Vue, Flutter).
 *
 * Uprawnienia (czyja to rezerwacja) sprawdza BookingPolicy w kontrolerze.
 * Tu są reguły biznesowe: bilety istnieją tylko dla opłaconej rezerwacji,
 * a anulowany bilet nie dostaje kodu QR.
 */
final class BookingTicketsService
{
    public function __construct(
        private readonly TicketPdfStore $pdfs,
        private readonly TicketQrRenderer $qr,
    ) {}

    /** @return array{content: string, filename: string} */
    public function pdf(Booking $booking): array
    {
        $this->assertPaid($booking);

        return [
            'content' => $this->pdfs->contents($booking),
            'filename' => $this->pdfs->fileName($booking),
        ];
    }

    /** PNG z kodem QR. Wykorzystany bilet dalej ma obraz — obsługa zobaczy przy skanie, że był użyty. */
    public function qrPng(Booking $booking, Ticket $ticket): string
    {
        $this->assertPaid($booking);

        if ($ticket->status === TicketStatus::Cancelled) {
            throw TicketValidationException::cancelled();
        }

        return $this->qr->png((string) $ticket->code);
    }

    private function assertPaid(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Paid) {
            throw new BookingTicketsUnavailableException($booking->status);
        }
    }
}
