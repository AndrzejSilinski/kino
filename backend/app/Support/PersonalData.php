<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimalizacja danych osobowych w panelu (Etap 7, blok I).
 *
 * Obsługa kina potrzebuje rozpoznać rezerwację (numer, imię i nazwisko przy kasie),
 * ale nie pełnego adresu e-mail klienta. Administrator widzi całość — obsługuje
 * reklamacje i zwroty.
 */
final class PersonalData
{
    /** "jan.kowalski@wp.pl" -> "j***@wp.pl"; niepoprawny adres -> "***". */
    public static function maskEmail(?string $email): string
    {
        if ($email === null || substr_count($email, '@') !== 1) {
            return '***';
        }

        [$local, $domain] = explode('@', $email);

        return $local === '' || $domain === ''
            ? '***'
            : mb_substr($local, 0, 1).'***@'.$domain;
    }
}
