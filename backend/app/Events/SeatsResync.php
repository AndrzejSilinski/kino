<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "Pobierz plan sali od nowa" — zamiast zbyt dużego SeatsChanged (Etap 6, blok F).
 *
 * Reverb przyjmuje żądanie publikacji do REVERB_MAX_REQUEST_SIZE bajtów
 * (domyślnie 10 000). Zmiana obejmująca bardzo wiele miejsc naraz (duży
 * sweep, anulowanie seansu w Etapie 7) mogłaby się nie zmieścić. Dzielenie
 * na części zepsułoby wersjonowanie (jedna wersja = jedno zdarzenie), więc
 * wysyłamy sam numer wersji, a klient pobiera snapshot GET seat-map.
 */
final class SeatsResync implements ShouldBroadcastNow
{
    public function __construct(
        private readonly int $screeningId,
        private readonly int $version,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('screenings.'.$this->screeningId)];
    }

    public function broadcastAs(): string
    {
        return 'seats.resync';
    }

    /** @return array{screening_id: int, version: int} */
    public function broadcastWith(): array
    {
        return [
            'screening_id' => $this->screeningId,
            'version' => $this->version,
        ];
    }
}
