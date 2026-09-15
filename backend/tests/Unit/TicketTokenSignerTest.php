<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Tickets\TicketTokenSigner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Podpis kodu QR. Czysty PHPUnit: klasa nie ma zależności od Laravela,
 * a klucz podajemy jawnie, więc test nie zależy od .env ani phpunit.xml.
 */
final class TicketTokenSignerTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const CODE = '3f2b8c1e-4d5a-4b6c-8d7e-9f0a1b2c3d4e';

    private TicketTokenSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new TicketTokenSigner(self::KEY);
    }

    public function test_token_ma_format_wersja_uuid_podpis(): void
    {
        $token = $this->signer->sign(self::CODE);

        $this->assertMatchesRegularExpression('/^T1\.'.preg_quote(self::CODE, '/').'\.[A-Za-z0-9_-]{16}$/', $token);
        // Najwyżej 58 bajtów: tyle mieści QR wersji 6 przy korekcji H. Wersja 7+
        // ma wzorzec wyrównania na środku, pod logo, i część skanerów go nie odczyta.
        $this->assertSame(56, strlen($token));
    }

    public function test_poprawny_token_zwraca_kod_biletu(): void
    {
        $token = $this->signer->sign(self::CODE);

        $this->assertSame(self::CODE, $this->signer->verify($token));
    }

    public function test_podpis_jest_deterministyczny(): void
    {
        // Ten sam bilet zawsze daje ten sam kod QR — PDF wygenerowany
        // ponownie z historii zakupów pokaże identyczny obraz.
        $this->assertSame($this->signer->sign(self::CODE), $this->signer->sign(self::CODE));
    }

    public function test_podmiana_uuid_przy_starym_podpisie_jest_odrzucana(): void
    {
        [, , $mac] = explode('.', $this->signer->sign(self::CODE));
        $otherCode = '3f2b8c1e-4d5a-4b6c-8d7e-9f0a1b2c3d4f';

        // Scenariusz ataku: ktoś zna podpis jednego biletu i dokleja go
        // do cudzego UUID (np. ze zrzutu bazy).
        $this->assertNull($this->signer->verify('T1.'.$otherCode.'.'.$mac));
    }

    public function test_token_podpisany_innym_kluczem_jest_odrzucany(): void
    {
        $foreign = new TicketTokenSigner(str_repeat('z', 64));

        $this->assertNull($this->signer->verify($foreign->sign(self::CODE)));
    }

    public function test_zmieniona_wersja_formatu_jest_odrzucana(): void
    {
        $token = $this->signer->sign(self::CODE);

        $this->assertNull($this->signer->verify('T2'.substr($token, 2)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'pusty' => [''],
            'samo uuid' => [self::CODE],
            'za dużo części' => ['T1.a.b.c'],
            'wielkie litery w uuid' => ['T1.'.strtoupper(self::CODE).'.AAAAAAAAAAAAAAAAAAAAAA'],
            'za długi' => [str_repeat('T', 500)],
            'adres URL z plakatu' => ['https://example.com/premiera'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTokens')]
    public function test_znieksztalcony_token_jest_odrzucany(string $token): void
    {
        $this->assertNull($this->signer->verify($token));
    }

    public function test_podpisanie_czegos_innego_niz_uuid_v4_jest_bledem_programisty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->signer->sign('42');
    }

    public function test_brak_klucza_zatrzymuje_aplikacje(): void
    {
        $this->expectException(RuntimeException::class);

        new TicketTokenSigner('');
    }
}
