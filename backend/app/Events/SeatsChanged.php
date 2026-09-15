<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Zmiana stanu miejsc na seansie — zdarzenie WebSocket (Etap 6, blok F).
 *
 * Kanał private-screenings.{id}, nazwa "seats.changed". Payload to STAN
 * ABSOLUTNY pogrupowany po statusie, a nie delta:
 *
 *     {"screening_id": 42, "version": 1187, "seats": {"held": [311, 312]}}
 *
 * BEZ DANYCH OSOBOWYCH (wymóg 1.3, decyzja 26): tylko identyfikatory miejsc,
 * status i numer wersji. Żadnej sesji, użytkownika ani rezerwacji — broadcast
 * nie mówi, CZYJA jest blokada; klient zna swój koszyk z odpowiedzi REST.
 *
 * WŁAŚCIWOŚCI PRYWATNE i jawne broadcastWith(): bez tego Laravel wysłałby
 * wszystkie publiczne właściwości zdarzenia (BroadcastEvent::getPayloadFromEvent).
 *
 * ShouldBroadcastNow, nie kolejka: spóźnione zdarzenie jest bezwartościowe,
 * a ponowienie z kolejki po 10–40 s dostarczyłoby stan już nieaktualny.
 * Odporność na niedziałający Reverb zapewnia RealtimeNotifier.
 */
final class SeatsChanged implements ShouldBroadcastNow
{
    /**
     * @param  array<string, list<int>>  $seats  status => posortowane id miejsc
     */
    public function __construct(
        private readonly int $screeningId,
        private readonly int $version,
        private readonly array $seats,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('screenings.'.$this->screeningId)];
    }

    public function broadcastAs(): string
    {
        return 'seats.changed';
    }

    /**
     * @return array{screening_id: int, version: int, seats: array<string, list<int>>}
     */
    public function broadcastWith(): array
    {
        return [
            'screening_id' => $this->screeningId,
            'version' => $this->version,
            'seats' => $this->seats,
        ];
    }
}
