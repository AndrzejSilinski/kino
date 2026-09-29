<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * GET /api/v1/client-config (Etap 8, blok C): konfiguracja klienta w czasie działania.
 *
 * Najważniejszy jest drugi test: endpoint jest publiczny i cachowany, więc jedna pomyłka
 * (np. cały config('broadcasting.connections.reverb')) wystawiłaby sekret podpisu kanałów.
 */
final class ClientConfigApiTest extends TestCase
{
    public function test_returns_public_realtime_key_and_booking_limits_from_config(): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'klucz-publiczny-testowy',
            'cinema.seat_lock.ttl' => 420,
            'cinema.seat_lock.max_seats_per_session' => 6,
            'payments.window_seconds' => 900,
        ]);

        $this->getJson('/api/v1/client-config')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'api_version' => 'v1',
                    'realtime' => ['broadcaster' => 'reverb', 'key' => 'klucz-publiczny-testowy', 'path' => '/app'],
                    'booking' => ['seat_lock_ttl_seconds' => 420, 'max_seats_per_session' => 6, 'payment_window_seconds' => 900],
                    'push' => ['enabled' => false],
                ],
            ]);
    }

    public function test_push_is_enabled_only_with_complete_public_firebase_config(): void
    {
        $web = [
            'push.enabled' => true,
            'push.fcm.project_id' => 'kino-test',
            'push.web.api_key' => 'publiczny-klucz-web',
            'push.web.app_id' => '1:1234567890:web:abcdef',
            'push.web.messaging_sender_id' => '1234567890',
            'push.web.vapid_public_key' => 'BPublicznyKluczVapid',
            'push.fcm.credentials' => '/run/secrets/cinema/firebase-service-account.json',
            // Blok ANDROID wyciszamy JAWNIE, choć ten test go nie dotyczy (ma własny niżej).
            // Bez tej linii wynik zależałby od tego, czy uruchamiająca maszyna ma w .env
            // wypełnione FIREBASE_ANDROID_APP_ID: u kogoś z konfiguracją pod telefon
            // kontroler dokłada blok `android`, a porównanie całej sekcji `push` przestaje
            // się zgadzać. Test czytający lokalne .env nie jest powtarzalny (pułapka EF).
            'push.android.app_id' => '',
        ];
        config($web);

        $response = $this->getJson('/api/v1/client-config')->assertOk()
            ->assertJsonPath('data.push', ['enabled' => true, 'firebase' => [
                'api_key' => 'publiczny-klucz-web',
                'app_id' => '1:1234567890:web:abcdef',
                'project_id' => 'kino-test',
                'messaging_sender_id' => '1234567890',
                'vapid_public_key' => 'BPublicznyKluczVapid',
            ]]);
        $this->assertStringNotContainsString('firebase-service-account', (string) $response->getContent(), 'Ścieżka pliku z sekretem nie wychodzi do klienta.');

        // Niekompletna konfiguracja WEBOWA zabiera tylko blok `firebase`. Kanał zostaje
        // włączony, bo serwer nadal ma czym wysyłać — a wdrożenie z samą aplikacją Android
        // jest równie poprawne jak z samą webową (Etap 9, decyzja 340).
        config(['push.web.vapid_public_key' => '']);
        $this->getJson('/api/v1/client-config')->assertJsonPath('data.push', ['enabled' => true]);

        // Dopiero brak tego, czym wysyła SERWER, wyłącza kanał.
        config(['push.fcm.credentials' => '']);
        $this->getJson('/api/v1/client-config')->assertJsonPath('data.push', ['enabled' => false]);
    }

    public function test_android_block_is_published_next_to_the_web_one(): void
    {
        // Aplikacja mobilna ma swoją konfigurację w google-services.json wkompilowanym w APK.
        // Te wartości służą jej wyłącznie do sprawdzenia, czy telefon i serwer mówią o TYM SAMYM
        // projekcie Firebase — niezgodność kończy się ciszą, bez żadnego błędu po drodze.
        config([
            'push.enabled' => true,
            'push.fcm.project_id' => 'kino-test',
            'push.fcm.credentials' => '/run/secrets/cinema/firebase-service-account.json',
            'push.android.app_id' => '1:1234567890:android:abcdef',
            'push.android.package_name' => 'pl.silinski.cinema',
        ]);

        $this->getJson('/api/v1/client-config')->assertOk()
            ->assertJsonPath('data.push.enabled', true)
            ->assertJsonPath('data.push.android', [
                'project_id' => 'kino-test',
                'app_id' => '1:1234567890:android:abcdef',
                'package_name' => 'pl.silinski.cinema',
            ])
            // Bez konfiguracji webowej bloku `firebase` nie ma, a kanał działa.
            ->assertJsonMissingPath('data.push.firebase');

        config(['push.android.app_id' => '']);
        $this->getJson('/api/v1/client-config')->assertOk()
            ->assertJsonPath('data.push.enabled', true)
            ->assertJsonMissingPath('data.push.android');
    }

    public function test_never_exposes_secrets(): void
    {
        // Fikcyjne klucze składane z części: pełny literał 'sk_test_…' albo 'whsec_…' w pliku
        // zatrzymałby skaner sekretów wykonawcy paczek (pułapka BX).
        $secrets = [
            'broadcasting.connections.reverb.secret' => 'sekret-reverb-0123456789abcdef',
            'payments.stripe.secret_key' => 'sk_'.'test_'.'sekretStripeTestowy0123456789',
            'payments.stripe.webhook_secret' => 'whsec'.'_'.'sekretWebhookaTestowy0123456789',
            'app.key' => 'base64:c2VrcmV0LWFwbGlrYWNqaS0wMTIzNDU2Nzg5YWJjZGVm',
        ];
        config($secrets);

        $body = $this->getJson('/api/v1/client-config')->assertOk()->getContent();

        foreach ($secrets as $name => $value) {
            $this->assertStringNotContainsString($value, (string) $body, "Odpowiedź zawiera wartość {$name}.");
        }
        $this->assertStringNotContainsStringIgnoringCase('secret', (string) $body);
    }

    public function test_is_public_cacheable_and_outside_the_booking_session(): void
    {
        $response = $this->getJson('/api/v1/client-config')->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        // Konfiguracja nie tworzy sesji zakupowej: X-Session-Id nadaje dopiero plan sali i koszyk.
        $response->assertHeaderMissing('X-Session-Id');
    }
}
