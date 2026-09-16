<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidScreeningException;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * JEDYNE miejsce liczenia czasu seansu (Etap 7, blok G): panel, seeder i fabryka.
 *
 * Bufor jest globalny (config cinema.screening: SCREENING_ADS_MINUTES,
 * SCREENING_CLEANUP_BUFFER_MINUTES). Zmiana bufora działa na NOWE seanse;
 * istniejące mają zapisane ends_at i slot_ends_at, więc zmiana konfiguracji
 * nie przesuwa po cichu repertuaru, który klienci już widzieli.
 *
 * Klasa bez bazy i bez Laravela — test jednostkowy kolizji (wymóg 5.2 zadania)
 * tworzy ją z liczbami podanymi wprost.
 */
final class ScreeningTimeline
{
    public function __construct(
        public readonly int $adsMinutes,
        public readonly int $cleanupMinutes,
    ) {
        if ($adsMinutes < 0 || $cleanupMinutes < 0) {
            throw new InvalidArgumentException('Czas reklam i sprzątania nie może być ujemny.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('cinema.screening.ads_minutes', 15),
            (int) config('cinema.screening.cleanup_buffer_minutes', 20),
        );
    }

    public function slot(CarbonImmutable $startsAt, int $durationMinutes): ScreeningSlot
    {
        if ($durationMinutes < 1) {
            throw new InvalidArgumentException('Czas trwania filmu musi być dodatni.');
        }

        $startsAt = $startsAt->setTimezone('UTC')->startOfMinute();
        $endsAt = $startsAt->addMinutes($this->adsMinutes + $durationMinutes);

        return new ScreeningSlot($startsAt, $endsAt, $endsAt->addMinutes($this->cleanupMinutes));
    }

    /**
     * Godzina podana w panelu ("2026-10-02", "19:30") w strefie kina -> moment w UTC.
     *
     * Odrzucamy godziny, których w tej strefie nie ma albo są dwie:
     * - 2026-03-29 02:30 w Warszawie nie istnieje (zegary 02:00 -> 03:00);
     *   PHP po cichu zrobiłby z tego 03:30.
     * - 2026-10-25 02:30 zdarza się dwa razy (03:00 -> 02:00); PHP wybrałby
     *   jedną z nich, a bilet i repertuar mogłyby pokazać różne godziny.
     *
     * @throws InvalidScreeningException
     */
    public function localStart(string $date, string $time, string $timezone): CarbonImmutable
    {
        $input = $date.' '.$time;

        // Najpierw sam zapis: 2026-02-30 albo 24:10 PHP też by "naprawił" przesunięciem.
        if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})\z/', $input, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) $m[4] > 23 || (int) $m[5] > 59) {
            throw InvalidScreeningException::badTime($input);
        }

        $zone = new DateTimeZone($timezone);
        $local = CarbonImmutable::createFromFormat('!Y-m-d H:i', $input, $zone);

        // Poprawny zapis, a PHP zwrócił inną godzinę = tej godziny w strefie nie ma.
        if ($local === null || $local->format('Y-m-d H:i') !== $input) {
            throw InvalidScreeningException::nonexistentLocalTime($input, $timezone);
        }

        // Ta sama godzina na zegarze godzinę wcześniej albo później = cofnięcie zegarów.
        foreach ([-1, 1] as $hours) {
            $neighbour = $local->setTimezone('UTC')->addHours($hours)->setTimezone($zone);

            if ($neighbour->format('Y-m-d H:i') === $input) {
                throw InvalidScreeningException::ambiguousLocalTime($input, $timezone);
            }
        }

        return $local->setTimezone('UTC');
    }
}
