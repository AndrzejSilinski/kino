<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/client-config — konfiguracja aplikacji klienckich w czasie działania (Etap 8, blok C).
 *
 * PO CO: SPA (i w Etapie 9 Flutter) potrzebuje klucza publicznego Reverba i limitów koszyka.
 * Zmienne VITE_* zostałyby wpisane w zbudowany plik JS, więc ten sam build nie nadawałby się
 * do innego środowiska (Etap 10: jeden obraz dla dev i produkcji). Tu konfiguracja przychodzi
 * z serwera, który i tak zna ją z .env.
 *
 * TYLKO WARTOŚCI JAWNE Z NATURY: klucz publiczny Reverba jest widoczny w adresie WebSocketu,
 * limity i czasy są widoczne w działaniu aplikacji. Żadnych sekretów — pilnuje tego test.
 * Adres i port WebSocketu klient bierze z adresu strony (nginx przekazuje /app/ do Reverba),
 * dlatego nie ma tu REVERB_HOST: to adres wewnątrz sieci Dockera.
 *
 * Bez logiki biznesowej i bez bazy — kontroler tylko składa odpowiedź z konfiguracji.
 * Publiczny cache na 60 s: odczyt przy każdym starcie aplikacji, a zmiana klucza w .env
 * (restart kontenerów) dociera do klientów najpóźniej po minucie.
 */
final class ClientConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'api_version' => 'v1',
                'realtime' => [
                    'broadcaster' => 'reverb',
                    'key' => (string) config('broadcasting.connections.reverb.key'),
                    'path' => '/app',
                ],
                'booking' => [
                    'seat_lock_ttl_seconds' => (int) config('cinema.seat_lock.ttl'),
                    'max_seats_per_session' => (int) config('cinema.seat_lock.max_seats_per_session'),
                    'payment_window_seconds' => (int) config('payments.window_seconds'),
                ],
                // Web Push (FCM) dojdzie w blokach K i L; do tego czasu klient wie, że push jest wyłączony.
                'push' => [
                    'enabled' => false,
                ],
            ],
        ])->setPublic()->setMaxAge(60);
    }
}
