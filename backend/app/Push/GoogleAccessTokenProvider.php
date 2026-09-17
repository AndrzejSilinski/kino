<?php

declare(strict_types=1);

namespace App\Push;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Token dostępu OAuth 2.0 dla FCM z konta serwisowego (Etap 8, blok K) — bez google/auth.
 *
 * Przepływ z dokumentacji Google "Using OAuth 2.0 for Server to Server Applications" (HTTP/REST):
 *   1. JWT: nagłówek {"alg":"RS256","typ":"JWT"}, roszczenia iss (e-mail konta), scope,
 *      aud = adres wymiany tokenu, iat, exp (najwyżej godzina po iat),
 *   2. podpis SHA256withRSA kluczem prywatnym konta, części w Base64url łączone kropką,
 *   3. POST application/x-www-form-urlencoded: grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer
 *      i assertion=JWT; odpowiedź: access_token i expires_in.
 *
 * Token trzymamy w PAMIĘCI PROCESU (singleton w kontenerze), nie w Redisie: to sekret ważny godzinę,
 * a worker i tak żyje długo. Odnawiamy minutę przed końcem ważności albo po 401 z FCM (invalidate()).
 * Dane konta ładowane leniwie — aplikacja bez skonfigurowanego push startuje normalnie.
 */
final class GoogleAccessTokenProvider implements AccessTokenSource
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private const LIFETIME_SECONDS = 3600;

    private const REFRESH_MARGIN_SECONDS = 60;

    private ?ServiceAccountCredentials $credentials = null;

    private ?string $accessToken = null;

    private int $expiresAt = 0;

    /** @param  Closure(): ServiceAccountCredentials  $loadCredentials */
    public function __construct(
        private readonly Closure $loadCredentials,
        private readonly int $timeoutSeconds = 10,
    ) {}

    /** @throws PushConfigurationException|PushTemporarilyUnavailableException */
    public function token(): string
    {
        $now = CarbonImmutable::now()->getTimestamp();

        if ($this->accessToken !== null && $now < $this->expiresAt - self::REFRESH_MARGIN_SECONDS) {
            return $this->accessToken;
        }

        $credentials = $this->credentials ??= ($this->loadCredentials)();

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->post($credentials->tokenUri, [
                    'grant_type' => self::GRANT_TYPE,
                    'assertion' => $this->assertion($credentials, $now),
                ]);
        } catch (ConnectionException) {
            throw new PushTemporarilyUnavailableException('Brak połączenia z serwerem tokenów Google.');
        }

        if ($response->serverError()) {
            throw new PushTemporarilyUnavailableException('Serwer tokenów Google chwilowo niedostępny (HTTP '.$response->status().').');
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_numeric($expiresIn)) {
            // Tylko kod błędu OAuth (np. invalid_grant) — bez treści odpowiedzi i bez danych konta.
            $error = is_string($response->json('error')) ? $response->json('error') : 'nieznany';
            throw new PushConfigurationException('Google odrzucił token konta serwisowego FCM (HTTP '.$response->status().', '.$error.').');
        }

        $this->accessToken = $token;
        $this->expiresAt = $now + (int) $expiresIn;

        return $token;
    }

    /** FCM odpowiedział 401: token mógł zostać unieważniony wcześniej, niż mówi expires_in. */
    public function invalidate(): void
    {
        $this->accessToken = null;
        $this->expiresAt = 0;
    }

    private function assertion(ServiceAccountCredentials $credentials, int $now): string
    {
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        $signingInput = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$encode((string) json_encode([
                'iss' => $credentials->clientEmail,
                'scope' => self::SCOPE,
                'aud' => $credentials->tokenUri,
                'iat' => $now,
                'exp' => $now + self::LIFETIME_SECONDS,
            ], JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($credentials->privateKey());

        if ($key === false || ! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PushConfigurationException('Nie udało się wczytać klucza prywatnego konta serwisowego FCM.');
        }

        return $signingInput.'.'.$encode($signature);
    }
}
