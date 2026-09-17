<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Push\GoogleAccessTokenProvider;
use App\Push\PushConfigurationException;
use App\Push\PushTemporarilyUnavailableException;
use App\Push\ServiceAccountCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Token OAuth konta serwisowego bez google/auth (Etap 8, blok K).
 *
 * Klucz RSA generowany w teście — żadnego prawdziwego klucza w repozytorium. Podpis JWT
 * weryfikujemy kluczem publicznym, tak jak zrobiłby to serwer Google.
 */
final class GoogleAccessTokenProviderTest extends TestCase
{
    private const EMAIL = 'fcm-sender@kino-test.iam.gserviceaccount.com';

    /** @var array{private: string, public: string} */
    private static array $keys;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        self::$keys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    private function provider(): GoogleAccessTokenProvider
    {
        $json = (string) json_encode(['type' => 'service_account', 'client_email' => self::EMAIL, 'private_key' => self::$keys['private'], 'token_uri' => 'https://oauth2.googleapis.com/token']);

        return new GoogleAccessTokenProvider(static fn () => ServiceAccountCredentials::fromJson($json));
    }

    private static function decode(string $part): string
    {
        return (string) base64_decode(strtr($part, '-_', '+/'), true);
    }

    public function test_jwt_rs256_z_wymaganymi_roszczeniami_i_poprawnym_podpisem(): void
    {
        CarbonImmutable::setTestNow('2026-09-17 12:00:00');
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.atrapa', 'expires_in' => 3599, 'token_type' => 'Bearer'])]);

        $this->assertSame('ya29.atrapa', $this->provider()->token());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://oauth2.googleapis.com/token', $request->url());
            $this->assertTrue($request->isForm());
            $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['grant_type']);

            [$header, $claims, $signature] = explode('.', (string) $request['assertion']);
            $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode(self::decode($header), true));
            $now = CarbonImmutable::now()->getTimestamp();
            $this->assertSame([
                'iss' => self::EMAIL,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], json_decode(self::decode($claims), true));
            $this->assertSame(1, openssl_verify($header.'.'.$claims, self::decode($signature), self::$keys['public'], OPENSSL_ALGO_SHA256));

            return true;
        });
        CarbonImmutable::setTestNow();
    }

    public function test_token_z_pamieci_do_minuty_przed_wygasnieciem_potem_nowy(): void
    {
        CarbonImmutable::setTestNow('2026-09-17 12:00:00');
        Http::fakeSequence('oauth2.googleapis.com/*')
            ->push(['access_token' => 'pierwszy', 'expires_in' => 3600])
            ->push(['access_token' => 'drugi', 'expires_in' => 3600]);
        $provider = $this->provider();

        $this->assertSame('pierwszy', $provider->token());
        CarbonImmutable::setTestNow('2026-09-17 12:58:59');
        $this->assertSame('pierwszy', $provider->token());
        CarbonImmutable::setTestNow('2026-09-17 12:59:01');
        $this->assertSame('drugi', $provider->token());
        Http::assertSentCount(2);
        CarbonImmutable::setTestNow();
    }

    public function test_odmowa_google_bez_ujawniania_klucza_a_blad_serwera_do_ponowienia(): void
    {
        Http::fakeSequence('oauth2.googleapis.com/*')
            ->push(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)
            ->push('awaria', 503);
        $provider = $this->provider();

        try {
            $provider->token();
            $this->fail('Oczekiwano PushConfigurationException.');
        } catch (PushConfigurationException $e) {
            $this->assertStringContainsString('invalid_grant', $e->getMessage());
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }

        $this->expectException(PushTemporarilyUnavailableException::class);
        $provider->token();
    }

    public function test_plik_konta_serwisowego_jest_sprawdzany_a_klucz_ukryty_w_zrzutach(): void
    {
        $this->expectExceptionObject(new PushConfigurationException('W pliku konta serwisowego FCM brakuje pola private_key.'));

        $credentials = ServiceAccountCredentials::fromJson((string) json_encode(['client_email' => self::EMAIL, 'private_key' => self::$keys['private']]));
        $this->assertStringNotContainsString('PRIVATE KEY', print_r($credentials, true));
        $this->assertSame('https://oauth2.googleapis.com/token', $credentials->tokenUri);

        ServiceAccountCredentials::fromJson((string) json_encode(['client_email' => self::EMAIL]));
    }

    public function test_obcy_token_uri_odrzucony(): void
    {
        $this->expectException(PushConfigurationException::class);

        ServiceAccountCredentials::fromJson((string) json_encode(['client_email' => self::EMAIL, 'private_key' => 'x', 'token_uri' => 'https://evil.example/token']));
    }
}
