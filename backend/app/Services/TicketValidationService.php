<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketStatus;
use App\Exceptions\TicketValidationException;
use App\Models\Screening;
use App\Models\Ticket;
use App\Models\User;
use App\Tickets\TicketTokenSigner;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Skanowanie biletu przy wejściu na salę (decyzja 80).
 *
 * KOLEJNOŚĆ SPRAWDZEŃ, od najtańszego:
 *   1. podpis tokenu        — bez zapytania do bazy (decyzja 47)
 *   2. czy bilet istnieje
 *   3. czy jest na TEN seans
 *   4. czy nie jest anulowany albo już wykorzystany
 *   5. czy trwa okno wejścia (od N minut przed startem do końca filmu)
 *
 * SAMO OZNACZENIE jest atomowym UPDATE ... WHERE status = 'valid'. Dwa
 * skanery w tej samej chwili: baza zmieni wiersz dokładnie raz, drugi
 * UPDATE zwróci 0 zmienionych wierszy i dostanie TICKET_ALREADY_USED.
 * Ta sama zasada co przy blokadach miejsc (decyzja 11): o wyniku decyduje
 * baza, a nie wcześniejszy odczyt w PHP.
 *
 * Autoryzacja (kto może skanować na tym seansie) jest w ScreeningPolicy,
 * przed wywołaniem serwisu.
 */
final class TicketValidationService
{
    public function __construct(
        private readonly TicketTokenSigner $signer,
        private readonly int $opensMinutesBefore,
    ) {}

    public function validate(string $token, Screening $screening, User $staff): Ticket
    {
        $code = $this->signer->verify($token) ?? throw TicketValidationException::invalidToken();

        $ticket = Ticket::query()
            ->with(['seat', 'screening.movie', 'screening.hall.cinema'])
            ->where('code', $code)
            ->first() ?? throw TicketValidationException::notFound();

        $ticketScreening = $ticket->screening;
        $timezone = $ticketScreening->hall->cinema->timezone;

        if ((int) $ticket->screening_id !== (int) $screening->id) {
            throw TicketValidationException::wrongScreening($ticketScreening);
        }

        $this->assertStillValid($ticket, $timezone);

        $now = CarbonImmutable::now();
        $opensAt = $ticketScreening->starts_at->copy()->subMinutes($this->opensMinutesBefore);

        if ($now->lessThan($opensAt)) {
            throw TicketValidationException::notYetOpen($opensAt, $timezone);
        }

        if ($now->greaterThan($ticketScreening->ends_at)) {
            throw TicketValidationException::windowClosed($ticketScreening->ends_at, $timezone);
        }

        $marked = Ticket::query()
            ->whereKey($ticket->id)
            ->where('status', TicketStatus::Valid)
            ->update([
                'status' => TicketStatus::Used,
                'validated_at' => $now,
                'validated_by_user_id' => $staff->id,
                'updated_at' => $now,
            ]);

        if ($marked === 0) {
            // Ktoś zeskanował albo anulował bilet między odczytem a zapisem.
            // Świeży stan z bazy mówi, który to był przypadek.
            $this->assertStillValid($ticket->refresh(), $timezone);

            throw new LogicException('Bilet '.$ticket->id.' jest ważny, a mimo to nie został oznaczony.');
        }

        return $ticket->refresh();
    }

    private function assertStillValid(Ticket $ticket, string $timezone): void
    {
        match ($ticket->status) {
            TicketStatus::Used => throw TicketValidationException::alreadyUsed($ticket->validated_at, $timezone),
            TicketStatus::Cancelled => throw TicketValidationException::cancelled(),
            TicketStatus::Valid => null,
        };
    }
}
