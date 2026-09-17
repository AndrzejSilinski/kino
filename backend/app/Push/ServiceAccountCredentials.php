<?php

declare(strict_types=1);

namespace App\Push;

/**
 * Dane konta serwisowego Google z pliku JSON (Etap 8, blok K).
 *
 * Czytamy tylko trzy pola: client_email (iss w JWT), private_key (podpis) i token_uri.
 * Klucz prywatny jest w pamięci procesu i nigdzie nie jest zapisywany ani logowany;
 * __debugInfo ukrywa go przed dump()/var_dump() i raportami błędów.
 */
final class ServiceAccountCredentials
{
    public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private function __construct(
        public readonly string $clientEmail,
        #[\SensitiveParameter] private readonly string $privateKey,
        public readonly string $tokenUri,
    ) {}

    public static function fromFile(?string $path): self
    {
        if ($path === null || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new PushConfigurationException('Plik konta serwisowego FCM nie istnieje albo nie da się go odczytać (FCM_CREDENTIALS).');
        }

        return self::fromJson((string) file_get_contents($path));
    }

    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new PushConfigurationException('Plik konta serwisowego FCM nie jest poprawnym JSON-em.');
        }

        foreach (['client_email', 'private_key'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new PushConfigurationException("W pliku konta serwisowego FCM brakuje pola {$field}.");
            }
        }

        $tokenUri = is_string($data['token_uri'] ?? null) ? $data['token_uri'] : self::DEFAULT_TOKEN_URI;

        // Adres wymiany tokenu tylko Google'a: podmieniony plik nie może wysłać podpisanego JWT gdzie indziej.
        if (parse_url($tokenUri, PHP_URL_SCHEME) !== 'https' || parse_url($tokenUri, PHP_URL_HOST) !== 'oauth2.googleapis.com') {
            throw new PushConfigurationException('Nieoczekiwany token_uri w pliku konta serwisowego FCM.');
        }

        return new self($data['client_email'], $data['private_key'], $tokenUri);
    }

    public function privateKey(): string
    {
        return $this->privateKey;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['clientEmail' => $this->clientEmail, 'privateKey' => '[ukryty]', 'tokenUri' => $this->tokenUri];
    }
}
