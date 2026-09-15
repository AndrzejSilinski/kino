<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Enums\BookingStatus;
use App\Enums\SeatType;
use App\Enums\TicketStatus;
use App\Models\Booking;
use App\Models\Ticket;
use App\Support\Labels;
use App\Support\Money;
use LogicException;

/**
 * Teksty o biletach rezerwacji — jedno źródło dla PDF-a i maila (decyzja 76).
 *
 * Formatowanie (strefa czasowa kina, polskie nazwy dni i miesięcy, kwoty
 * przez Money, etykiety enumów) odbywa się TYLKO tutaj, więc PDF i mail
 * nie pokażą klientowi dwóch różnych godzin tego samego seansu.
 *
 * Wynik to zwykła tablica tekstów, bez modeli: widok Blade i treść maila
 * nie wykonują zapytań, także w workerze (pułapka R).
 *
 * Pole tickets.*.code służy WYŁĄCZNIE do wygenerowania kodu QR. Nie wolno
 * go wypisywać jako tekstu — TicketPdfRenderer usuwa je, zanim dane trafią
 * do widoku (decyzja 68).
 */
final class BookingTicketsPresenter
{
    public function __construct(private readonly int $adsMinutes) {}

    /** @return array<string, mixed> */
    public function present(Booking $booking): array
    {
        // Obrona w głębi: nawet gdyby policy albo job przepuściły anulowaną
        // czy zwróconą rezerwację, nie powstanie dokument wyglądający na bilet.
        if ($booking->status !== BookingStatus::Paid) {
            throw new LogicException('Bilety pokazujemy wyłącznie dla opłaconej rezerwacji.');
        }

        // loadMissing nie pobiera drugi raz relacji, które wywołujący już załadował.
        $booking->loadMissing(['screening.movie', 'screening.hall.cinema', 'tickets.seat']);

        $screening = $booking->screening;
        $cinema = $screening->hall->cinema;
        // Decyzja 24: godzina seansu zawsze w strefie kina, nie serwera (UTC).
        $startsAt = $screening->starts_at->copy()->setTimezone($cinema->timezone)->locale('pl');

        $tickets = $booking->tickets
            ->reject(fn (Ticket $ticket): bool => $ticket->status === TicketStatus::Cancelled)
            ->sort(fn (Ticket $a, Ticket $b): int => [$a->seat->row_label, $a->seat->seat_number]
                <=> [$b->seat->row_label, $b->seat->seat_number])
            ->values();

        if ($tickets->isEmpty()) {
            throw new LogicException('Opłacona rezerwacja '.$booking->reference.' nie ma żadnego ważnego biletu.');
        }

        return [
            'reference' => $booking->reference,
            'total' => Money::minor($booking->total_amount, $booking->currency)->toArray()['formatted'],
            'movie' => [
                'title' => $screening->movie->title,
                'age_rating' => $screening->movie->age_rating,
                'duration' => $screening->movie->duration_minutes.' min',
            ],
            'cinema' => [
                'name' => $cinema->name,
                'address' => $cinema->address.', '.$cinema->city,
                'hall' => $screening->hall->name,
            ],
            'screening' => [
                'date' => $startsAt->isoFormat('dddd, D MMMM YYYY'),
                'time' => $startsAt->format('H:i'),
                'version' => Labels::projectionType($screening->projection_type)
                    .' · '.Labels::languageVersion($screening->language_version),
                'ads_minutes' => $this->adsMinutes,
            ],
            'tickets' => $tickets->map(fn (Ticket $ticket, int $index): array => [
                'number' => $index + 1,
                'count' => $tickets->count(),
                'row' => $ticket->seat->row_label,
                'seat' => $ticket->seat->seat_number,
                'label' => 'Rząd '.$ticket->seat->row_label.', miejsce '.$ticket->seat->seat_number,
                'seat_type' => $ticket->seat->type === SeatType::Standard ? null : $ticket->seat->type->label(),
                'price' => Money::minor($ticket->price, $booking->currency)->toArray()['formatted'],
                'status' => $ticket->status === TicketStatus::Valid ? null : Labels::ticketStatus($ticket->status),
                'code' => (string) $ticket->code,
            ])->all(),
        ];
    }
}
