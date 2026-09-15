<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Wpina RetryPolicy w zadanie, notyfikację albo mailable.
 *
 * DLACZEGO WŁAŚCIWOŚĆ $tries, A NIE METODA tries():
 * Laravel 13.26 czyta liczbę prób różnie w zależności od typu obiektu
 * (sprawdzone w vendor/):
 *   - zadanie (job):  atrybut #[Tries], właściwość $tries albo metoda tries()
 *   - notyfikacja:    tylko atrybut albo właściwość (SendQueuedNotifications)
 *   - mailable:       tylko atrybut albo właściwość (SendQueuedMailable)
 * Metoda tries() w notyfikacji zostałaby po cichu pominięta i mail dostałby
 * liczbę prób z opcji --tries workera. Właściwość działa we wszystkich trzech.
 *
 * DLACZEGO METODA backoff(), A NIE ATRYBUT #[Backoff]:
 * metodę rozumieją wszystkie trzy typy, a atrybut przyjmuje wyłącznie
 * stałe wartości — nie da się w nim wylosować rozrzutu.
 */
trait UsesRetryPolicy
{
    /** Łączna liczba prób. Laravel odczytuje ją przy wstawianiu do kolejki. */
    public int $tries = RetryPolicy::MAX_ATTEMPTS;

    /**
     * Opóźnienia przed kolejnymi próbami, w sekundach.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return RetryPolicy::delays();
    }
}
