<?php

declare(strict_types=1);

namespace App\Push;

/**
 * Treść powiadomienia push (Etap 8, blok K), niezależna od FCM i platformy.
 *
 * Tytuł i treść są widoczne na zablokowanym ekranie — dlatego BEZ danych osobowych
 * (bez imienia, e-maila, numeru rezerwacji, miejsc): tylko tytuł filmu i termin seansu.
 * $url to ścieżka w aplikacji (np. /bookings/{reference}) do otwarcia po kliknięciu —
 * trafia do sekcji data, której system nie wyświetla.
 */
final readonly class PushMessage
{
    /** @param  array<string, string>  $data  FCM przyjmuje w data wyłącznie wartości tekstowe */
    public function __construct(
        public string $title,
        public string $body,
        public string $type,
        public string $url,
        public array $data = [],
    ) {}
}
