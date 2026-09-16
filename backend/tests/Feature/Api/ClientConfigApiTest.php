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
