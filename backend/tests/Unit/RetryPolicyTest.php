<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Polityka ponowień to kontrakt z treści zadania (max 3 próby,
 * wykładnicze opóźnienie), więc pilnuje go test.
 *
 * Czysty PHPUnit\Framework\TestCase — bez bazy i bez aplikacji Laravela,
 * bo RetryPolicy nie ma żadnych zależności. Test trwa milisekundy.
 */
final class RetryPolicyTest extends TestCase
{
    public function test_trzy_proby_to_dwa_ponowienia(): void
    {
        $this->assertSame(3, RetryPolicy::MAX_ATTEMPTS);
        $this->assertCount(RetryPolicy::MAX_ATTEMPTS - 1, RetryPolicy::baseDelays());
    }

    public function test_opoznienia_bazowe_rosna_wykladniczo(): void
    {
        $this->assertSame([10, 40], RetryPolicy::baseDelays());
    }

    public function test_rozrzut_miesci_sie_w_granicach(): void
    {
        $base = RetryPolicy::baseDelays();

        // Losowości nie testujemy konkretną wartością, bo ta z definicji
        // się zmienia. Losujemy wiele razy i sprawdzamy granice.
        for ($i = 0; $i < 200; $i++) {
            foreach (RetryPolicy::delays() as $index => $delay) {
                $this->assertGreaterThanOrEqual($base[$index], $delay);
                $this->assertLessThanOrEqual($this->maxWithJitter($base[$index]), $delay);
            }
        }
    }

    public function test_kolejne_ponowienie_zawsze_czeka_dluzej(): void
    {
        $base = RetryPolicy::baseDelays();

        // Nawet najgorsze losowanie nie może odwrócić kolejności:
        // najdłuższe opóźnienie próby N jest krótsze niż najkrótsze N+1.
        for ($index = 0; $index < count($base) - 1; $index++) {
            $this->assertLessThan($base[$index + 1], $this->maxWithJitter($base[$index]));
        }
    }

    private function maxWithJitter(int $delay): int
    {
        return $delay + intdiv($delay * RetryPolicy::JITTER_PERCENT, 100);
    }
}
