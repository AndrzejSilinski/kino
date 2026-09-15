<?php

declare(strict_types=1);

namespace App\Tickets;

use InvalidArgumentException;
use RuntimeException;

/**
 * Podpisuje i weryfikuje zawartość kodu QR biletu.
 *
 * FORMAT: T1.{uuid}.{mac}
 *   T1   — wersja formatu i klucza; nowy format (np. Ed25519 dla skanera
 *          offline) dostanie T2, a stare bilety dalej będą ważne
 *   uuid — tickets.code (UUID v4, 122 losowe bity)
 *   mac  — base64url z pierwszych 12 bajtów HMAC-SHA256("T1.{uuid}")
 *
 * CO DAJE PODPIS (decyzja 47):
 *   1. zrzut tabeli tickets nie wystarcza do wydrukowania biletu —
 *      trzeba jeszcze klucza, który leży w .env, a nie w bazie,
 *   2. obcy albo podrobiony kod odpada bez zapytania do bazy,
 *   3. wersja w prefiksie pozwala zmienić format bez unieważniania
 *      sprzedanych biletów.
 *
 * CZEGO PODPIS NIE DAJE: nie mówi, czy bilet jest WAŻNY. Wykorzystany,
 * anulowany albo z innego seansu — to zawsze rozstrzyga baza.
 *
 * HMAC jest symetryczny, więc klucz NIGDY nie trafia do aplikacji
 * skanującej. Weryfikacja odbywa się wyłącznie na serwerze.
 */
final class TicketTokenSigner
{
    public const VERSION = 'T1';

    /** 12 bajtów = 96 bitów: nie do zgadnięcia online, a token (56 znaków) mieści się w QR wersji 6, która nie ma wzorca wyrównania pod logo (decyzja 66). */
    private const MAC_BYTES = 12;

    /** Minimalna długość klucza: 32 znaki (np. 64 znaki hex = 256 bitów). */
    private const MIN_KEY_LENGTH = 32;

    /** Tylko postać kanoniczna: małe litery, wersja 4. Jedna forma = brak niejednoznaczności. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** Górny limit długości tokenu: odrzucamy śmieci przed jakimkolwiek parsowaniem. */
    private const MAX_TOKEN_LENGTH = 128;

    public function __construct(
        #[\SensitiveParameter] private readonly string $key,
    ) {
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            // Brak klucza to błąd konfiguracji serwera, nie problem klienta.
            // Wolimy głośny wyjątek niż bilety podpisane pustym kluczem.
            throw new RuntimeException('Brak TICKET_QR_KEY albo klucz krótszy niż 32 znaki.');
        }
    }

    /** UUID biletu -> zawartość kodu QR. */
    public function sign(string $code): string
    {
        if (preg_match(self::UUID_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException('Kod biletu musi być UUID v4 w postaci kanonicznej.');
        }

        $payload = self::VERSION.'.'.$code;

        return $payload.'.'.$this->mac($payload);
    }

    /**
     * Zawartość kodu QR -> UUID biletu albo null.
     *
     * Celowo zwraca tylko null, bez powodu. Skaner nie potrzebuje wiedzieć,
     * czy zawiodła wersja, format czy podpis — a informacja "format dobry,
     * podpis zły" pomagałaby komuś, kto próbuje podrobić bilet.
     */
    public function verify(string $token): ?string
    {
        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$version, $code, $mac] = $parts;

        if ($version !== self::VERSION || preg_match(self::UUID_PATTERN, $code) !== 1) {
            return null;
        }

        // hash_equals porównuje w stałym czasie. Zwykłe === kończy na
        // pierwszym różnym znaku, więc z pomiaru czasu odpowiedzi dałoby
        // się odgadywać podpis znak po znaku (timing attack).
        if (! hash_equals($this->mac($version.'.'.$code), $mac)) {
            return null;
        }

        return $code;
    }

    private function mac(string $payload): string
    {
        $raw = hash_hmac('sha256', $payload, $this->key, true);

        // base64url: bez znaków + / =, które w QR i w URL wymagają kodowania.
        return rtrim(strtr(base64_encode(substr($raw, 0, self::MAC_BYTES)), '+/', '-_'), '=');
    }
}
