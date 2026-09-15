<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Rezerwacja opłacona, a pieniądze pobrane (decyzja 72).
 *
 * Emituje wyłącznie PaymentService, po udanym capture — nie BookingService
 * po wystawieniu biletów. Dla karty bilety powstają PRZED pobraniem
 * pieniędzy; gdyby capture przepadł, klient miałby już mail z biletami,
 * które chwilę później zostaną wycofane.
 *
 * Niesie tylko identyfikator: słuchacze sami pobierają świeży stan z bazy,
 * a w Etapie 6 to samo zdarzenie może trafić do kanału panelu admina bez
 * ryzyka, że wypłyną dane osobowe klienta.
 *
 * ShouldDispatchAfterCommit: gdyby ktoś kiedyś wywołał ogłoszenie wewnątrz
 * transakcji, słuchacze dostaną je dopiero po commicie — nie zobaczą
 * rezerwacji, która za chwilę może zostać wycofana rollbackiem.
 */
final class BookingPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $bookingId) {}
}
