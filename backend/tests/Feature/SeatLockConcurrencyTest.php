<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\Screening;
use App\Models\SeatLock;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * OBOWIĄZKOWY TEST WSPÓŁBIEŻNOŚCI (sekcja 1.2 zadania).
 *
 * Dowodzi, że przy N równoczesnych żądaniach na to samo miejsce powstaje
 * DOKŁADNIE JEDNA blokada, a pozostałe N-1 dostaje czytelny błąd 409.
 *
 * DLACZEGO DatabaseTruncation, A NIE RefreshDatabase — to najważniejsza
 * decyzja techniczna w tym pliku:
 * RefreshDatabase opakowuje każdy test w transakcję i wycofuje ją na końcu.
 * Dane zapisane w niezatwierdzonej transakcji są NIEWIDOCZNE dla innych połączeń,
 * więc procesy potomne nie zobaczyłyby ani seansu, ani miejsc — wszystkie padłyby
 * na "seans nie istnieje", a test byłby fałszywie zielony (zero blokad to przecież
 * nie jest "więcej niż jedna"). DatabaseTruncation czyści tabele zamiast otwierać
 * transakcję, więc dane są naprawdę zatwierdzone i widoczne z zewnątrz.
 *
 * Uruchomienie tylko tej grupy:  php artisan test --group=concurrency
 */
#[Group('concurrency')]
class SeatLockConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Ile sekund rodzic daje dzieciom na boot Laravela, zanim padnie strzał startera.
     * Za mało — część procesów wystartuje po barierze i kontencja będzie słabsza
     * (test nadal jest poprawny, tylko mniej wymagający). Za dużo — test się wlecze.
     */
    private const BOOT_DELAY_SECONDS = 3.0;

    public function test_dwadziescia_rownoczesnych_zadan_na_to_samo_miejsce_daje_dokladnie_jedna_blokade(): void
    {
        $hall = Hall::factory()->withSeats(1, 3)->create();
        $screening = Screening::factory()->for($hall)->create();
        $seatId = (int) $hall->seats()->orderBy('id')->value('id');

        $results = $this->runConcurrently($screening, 20, fn (int $i): array => [
            'seats' => [$seatId],
            'session' => "sesja-{$i}",
        ]);

        $locked = $this->withResult($results, 'locked');
        $rejected = $this->withResult($results, 'rejected');
        $crashed = $this->withResult($results, 'crashed');

        $this->assertSame(
            [], $crashed,
            'Żaden proces nie może paść z niekontrolowanym wyjątkiem: '.json_encode($crashed)
        );

        $this->assertCount(1, $locked, 'Dokładnie jeden proces ma zdobyć miejsce.');
        $this->assertCount(19, $rejected, 'Pozostałe 19 musi dostać kontrolowaną odmowę.');

        foreach ($rejected as $result) {
            $this->assertSame('SEATS_UNAVAILABLE', $result['error_code']);
            $this->assertSame(409, $result['status'], 'Konflikt miejsca to 409, nie 500.');
        }

        $this->assertSame(1, SeatLock::query()
            ->where('screening_id', $screening->id)
            ->where('seat_id', $seatId)
            ->whereNull('released_at')
            ->count(), 'W bazie ma istnieć dokładnie jedna aktywna blokada tego miejsca.');

        $this->assertSame(1, SeatLock::query()->count(),
            'Wycofane transakcje nie mogą zostawić po sobie żadnych wierszy.');

        // Etap 6: 19 przegranych odpada na indeksie UNIQUE, zanim dotknie
        // licznika wersji — licznik podbiła wyłącznie zwycięska transakcja.
        $this->assertSame(1, $this->seatStateVersion($screening),
            'Przegrane transakcje nie mogą podbić wersji stanu miejsc.');
    }

    public function test_rownoczesne_zadania_na_rozne_miejsca_wszystkie_sie_udaja(): void
    {
        // Kontrola negatywna: udowadnia, że blokada NIE jest globalnym mutexem
        // na seans. Gdybyśmy użyli SELECT FOR UPDATE na wierszu screenings,
        // ten test nadal by przeszedł, ale wszystkie żądania stałyby w kolejce.
        $hall = Hall::factory()->withSeats(2, 6)->create();
        $screening = Screening::factory()->for($hall)->create();
        $seatIds = array_values($hall->seats()->orderBy('id')->pluck('id')->all());

        $results = $this->runConcurrently($screening, 10, fn (int $i): array => [
            'seats' => [(int) $seatIds[$i]],
            'session' => "sesja-{$i}",
        ]);

        $this->assertSame([], $this->withResult($results, 'crashed'));
        $this->assertCount(10, $this->withResult($results, 'locked'),
            'Różne miejsca nie kolidują — wszystkie żądania muszą się powieść.');

        $this->assertSame(10, SeatLock::query()->whereNull('released_at')->count());

        // Etap 6: 10 równoległych zwycięzców = 10 kolejnych wersji. Żaden
        // przyrost nie zginął, bo licznik podbija się pod blokadą wiersza.
        $this->assertSame(10, $this->seatStateVersion($screening),
            'Każda zatwierdzona zmiana stanu musi dostać własny numer wersji.');
    }

    public function test_blokowanie_wielu_miejsc_naraz_nie_powoduje_deadlockow(): void
    {
        // Każdy proces bierze parę SĄSIADUJĄCYCH miejsc w pierścieniu: {i, i+1}.
        // Zbiory się nachodzą i domykają w cykl — to podręcznikowy przepis
        // na deadlock, jeśli kolejność blokowania zależy od kolejności wejścia.
        // SeatLockService sortuje seat_id rosnąco, więc cyklu nigdy nie ma:
        // ktokolwiek pierwszy zdobędzie niższy identyfikator, ten idzie dalej.
        $hall = Hall::factory()->withSeats(2, 6)->create();
        $screening = Screening::factory()->for($hall)->create();
        $seatIds = array_values($hall->seats()->orderBy('id')->pluck('id')->all());
        $total = count($seatIds);

        $results = $this->runConcurrently($screening, $total, fn (int $i): array => [
            'seats' => [(int) $seatIds[$i], (int) $seatIds[($i + 1) % $total]],
            'session' => "sesja-{$i}",
        ]);

        $this->assertSame([], $this->withResult($results, 'crashed'),
            'Deadlock objawiłby się tu jako wyjątek techniczny (SQLSTATE 40P01).');

        $duplicates = SeatLock::query()
            ->whereNull('released_at')
            ->selectRaw('seat_id, count(*) AS total')
            ->groupBy('seat_id')
            ->havingRaw('count(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates, 'Żadne miejsce nie może mieć dwóch aktywnych blokad.');

        $this->assertSame(count($this->withResult($results, 'locked')), $this->seatStateVersion($screening),
            'Wersja stanu miejsc = liczba udanych blokad, także przy nachodzących na siebie parach.');
    }

    /** Etap 6: aktualna wersja stanu miejsc seansu (0, gdy nic się nie zmieniło). */
    private function seatStateVersion(Screening $screening): int
    {
        return (int) (\Illuminate\Support\Facades\DB::table('screening_seat_versions')
            ->where('screening_id', $screening->id)
            ->value('version') ?? 0);
    }

    /**
     * Uruchamia $count procesów potomnych i zwraca ich odpowiedzi.
     *
     * @param  callable(int): array{seats: list<int>, session: string}  $plan
     * @return list<array<string, mixed>>
     */
    private function runConcurrently(Screening $screening, int $count, callable $plan): array
    {
        $startAt = microtime(true) + self::BOOT_DELAY_SECONDS;
        $environment = $this->childEnvironment();
        $handles = [];

        for ($i = 0; $i < $count; $i++) {
            $spec = $plan($i);

            // Komenda jako TABLICA, nie string: PHP przekazuje argumenty
            // bezpośrednio do execve, więc nie ma cudzysłowów, escapowania
            // ani ryzyka, że powłoka coś zinterpretuje po swojemu.
            $command = [
                PHP_BINARY,
                base_path('artisan'),
                'cinema:seat-lock:attempt',
                '--screening='.$screening->id,
                '--seats='.implode(',', $spec['seats']),
                '--session='.$spec['session'],
                '--at='.sprintf('%.6F', $startAt),
                '--no-ansi',
            ];

            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);

            $this->assertIsResource($process, 'Nie udało się uruchomić procesu potomnego.');

            $handles[] = ['process' => $process, 'pipes' => $pipes];
        }

        // Odczyt DOPIERO po uruchomieniu wszystkich procesów — stream_get_contents
        // blokuje do końca strumienia, więc czytanie w pętli tworzenia
        // zamieniłoby test w wykonanie sekwencyjne.
        $results = [];

        foreach ($handles as $handle) {
            $stdout = (string) stream_get_contents($handle['pipes'][1]);
            $stderr = (string) stream_get_contents($handle['pipes'][2]);

            fclose($handle['pipes'][1]);
            fclose($handle['pipes'][2]);
            proc_close($handle['process']);

            $results[] = $this->decodeResponse($stdout, $stderr);
        }

        return $results;
    }

    /**
     * Zmienne środowiskowe dla procesów potomnych.
     *
     * Ustawienia z sekcji <php> w phpunit.xml dotyczą wyłącznie procesu PHPUnit —
     * proces potomny ich NIE dziedziczy. Bez tego dziecko wczytałoby .env i poszło
     * na bazę deweloperską, a test cicho testowałby nie to, co trzeba.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}");

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'BROADCAST_CONNECTION' => 'null',
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'HOME' => '/tmp',
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ];
    }

    /** @return array<string, mixed> */
    private function decodeResponse(string $stdout, string $stderr): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $stdout)), fn (string $l): bool => $l !== '');

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['result'])) {
                return $decoded;
            }
        }

        return ['result' => 'crashed', 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function withResult(array $results, string $result): array
    {
        return array_values(array_filter($results, fn (array $r): bool => ($r['result'] ?? null) === $result));
    }

    /**
     * Czyści bazę po każdym teście tej klasy.
     *
     * DLACZEGO TO JEST KONIECZNE:
     * DatabaseTruncation czyści tabele PRZED testem, ale nie po nim — a ten test,
     * w odróżnieniu od pozostałych, zapisuje dane NAPRAWDĘ, bez transakcji do
     * wycofania (inaczej procesy potomne by ich nie zobaczyły). Zostawione wiersze
     * trafiały do testów jednostkowych, które używają RefreshDatabase i słusznie
     * zakładają, że startują z pustej bazy — stąd fałszywe porażki asercji
     * na bezwzględne liczniki.
     *
     * Reguła ogólna: test, który omija transakcję, odpowiada za sprzątanie po sobie.
     *
     * TRUNCATE zamiast DELETE, bo jest szybszy i nie zostawia martwych krotek;
     * CASCADE, bo tabele są spięte kluczami obcymi i kolejność czyszczenia miałaby
     * znaczenie; RESTART IDENTITY, żeby sekwencje identyfikatorów nie rosły w nieskończoność.
     * Tabela migrations zostaje nietknięta — inaczej framework uznałby bazę za niezmigrowaną.
     */
    protected function tearDown(): void
    {
        $tables = \Illuminate\Support\Facades\DB::select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"
        );

        if ($tables !== []) {
            $names = implode(', ', array_map(
                static fn (object $table): string => '"'.$table->tablename.'"',
                $tables
            ));

            \Illuminate\Support\Facades\DB::statement("TRUNCATE {$names} RESTART IDENTITY CASCADE");
        }

        parent::tearDown();
    }
}
