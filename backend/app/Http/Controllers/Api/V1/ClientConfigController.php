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
                // Push przez FCM: konfiguracja aplikacji WEB (blok L Etapu 8) i ANDROID (Etap 9).
                // Plik konta serwisowego (sekret) NIGDY tu nie trafia — tylko wartości, które i tak
                // widzi każdy klient korzystający z Firebase.
                'push' => $this->push(),
            ],
        ])->setPublic()->setMaxAge(60);
    }

    /**
     * Stan kanału push i konfiguracja aplikacji klienckich.
     *
     * `enabled` mówi o SERWERZE: czy kanał jest włączony i czy jest czym wysyłać (identyfikator
     * projektu i plik konta serwisowego). Konfiguracja aplikacji klienckich jest osobno, po jednym
     * bloku na platformę, bo brak jednej nie unieważnia drugiej — wdrożenie z samą aplikacją
     * Android jest równie poprawne jak z samą webową (Etap 9, decyzja 340). Wcześniej `enabled`
     * zależało od kompletu wartości WEBOWYCH, więc kino bez aplikacji web widziałoby push jako
     * wyłączony także na telefonie.
     *
     * @return array{enabled: bool, firebase?: array<string, string>, android?: array<string, string>}
     */
    private function push(): array
    {
        $projectId = (string) config('push.fcm.project_id');

        if (! config('push.enabled') || $projectId === '' || (string) config('push.fcm.credentials') === '') {
            return ['enabled' => false];
        }

        $push = ['enabled' => true];

        $web = [
            'api_key' => (string) config('push.web.api_key'),
            'app_id' => (string) config('push.web.app_id'),
            'project_id' => $projectId,
            'messaging_sender_id' => (string) config('push.web.messaging_sender_id'),
            'vapid_public_key' => (string) config('push.web.vapid_public_key'),
        ];

        if (! in_array('', $web, true)) {
            $push['firebase'] = $web;
        }

        $android = [
            'project_id' => $projectId,
            'app_id' => (string) config('push.android.app_id'),
            'package_name' => (string) config('push.android.package_name'),
        ];

        if (! in_array('', $android, true)) {
            $push['android'] = $android;
        }

        return $push;
    }
}
