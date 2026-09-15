<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\BookingPaid;
use App\Services\RealtimeNotifier;

/**
 * Opłacenie rezerwacji na żywo: kanał właściciela i feed sprzedaży (Etap 6, blok G).
 *
 * DLACZEGO Z BookingPaid, A NIE Z BookingService::fulfil(): fulfil() ustawia
 * status paid PRZED pobraniem pieniędzy (decyzja 72). Gdyby capture przepadł,
 * panel pokazałby sprzedaż, której nie było. BookingPaid emituje wyłącznie
 * PaymentService po udanym capture — ta sama granica co mail z biletami.
 *
 * Synchronicznie, nie ShouldQueue: to dwa krótkie żądania HTTP, a awarię
 * Reverba łapie RealtimeNotifier (limit 0,5 s na połączenie). BookingPaid
 * jest ShouldDispatchAfterCommit, więc słuchacz nie zobaczy stanu sprzed COMMIT.
 *
 * Laravel wykrywa słuchacza po typie parametru handle() — bez rejestracji.
 */
final class BroadcastBookingPaid
{
    public function __construct(
        private readonly RealtimeNotifier $realtime,
    ) {}

    public function handle(BookingPaid $event): void
    {
        $this->realtime->bookingChanged($event->bookingId, BookingStatus::Paid);
    }
}
