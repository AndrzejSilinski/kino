<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Zaufane proxy (Etap 10, blok E): config/trustedproxy.php i TRUSTED_PROXIES.
 *
 * Najważniejszy jest przypadek domyślny: bez listy nagłówki X-Forwarded-* od klienta nic nie
 * znaczą. Inaczej każdy mógłby podać sobie cudze IP (limity logowania i blokad miejsc są
 * liczone po IP) albo udawać połączenie HTTPS.
 */
final class TrustedProxiesTest extends TestCase
{
    private const PROXY = '10.20.0.5';

    protected function setUp(): void
    {
        parent::setUp();

        // Trasa tylko na czas testu: pokazuje, co aplikacja wie o żądaniu.
        Route::get('/_test/proxy', static fn (Request $request): array => [
            'secure' => $request->secure(),
            'ip' => $request->ip(),
            'url' => url('/sciezka'),
        ]);
    }

    public function test_bez_listy_naglowki_klienta_sa_ignorowane(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->fromAddress(self::PROXY)
            ->assertJson(['secure' => false, 'ip' => self::PROXY, 'url' => $this->ownUrl()]);
    }

    public function test_zaufane_proxy_przekazuje_schemat_i_adres_klienta(): void
    {
        config(['trustedproxy.proxies' => '10.20.0.0/16, 192.0.2.1']);

        $this->fromAddress(self::PROXY)
            ->assertJson(['secure' => true, 'ip' => '203.0.113.7', 'url' => 'https://kino.example/sciezka']);
    }

    public function test_adres_spoza_listy_nie_moze_podszyc_sie_pod_proxy(): void
    {
        config(['trustedproxy.proxies' => '10.20.0.0/16']);

        $this->fromAddress('198.51.100.9')
            ->assertJson(['secure' => false, 'ip' => '198.51.100.9', 'url' => $this->ownUrl()]);
    }

    public function test_zmienna_srodowiskowa_trafia_do_konfiguracji_a_pusta_znaczy_brak_zaufanych(): void
    {
        // TRUSTED_PROXIES= w .env daje pusty napis — plik konfiguracji zamienia go na null,
        // bo pusty napis rozbity po przecinkach dałby listę z jednym pustym adresem.
        $this->assertSame('10.20.0.0/16,192.0.2.1', $this->configWithEnv('10.20.0.0/16,192.0.2.1'));
        $this->assertNull($this->configWithEnv(''));
    }

    private function configWithEnv(string $value): ?string
    {
        putenv('TRUSTED_PROXIES='.$value);
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;
        try {
            return (require config_path('trustedproxy.php'))['proxies'];
        } finally {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }
    }

    /** Adres, pod którym test wysyła żądanie (APP_URL), gdy nagłówki proxy nic nie znaczą. */
    private function ownUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/sciezka';
    }

    private function fromAddress(string $remoteAddr): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'kino.example',
                'X-Forwarded-Port' => '443',
            ])
            ->getJson('/_test/proxy')
            ->assertOk();
    }
}
