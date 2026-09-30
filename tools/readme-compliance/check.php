<?php

declare(strict_types=1);

/*
 * Zgodność README z kodem (Etap 7, blok N2).
 *
 * README opisuje klasy, metody, kody błędów, zmienne, trasy, komendy, indeksy
 * i liczby testów. Każda z tych nazw w `backtickach` sekcji etapu musi istnieć
 * w repozytorium, a tabela testów musi się zgadzać z tym, co zbiera PHPUnit.
 * Nazwy wpisane z pamięci i przestarzałe po refaktoryzacji wychodzą tu, a nie
 * na rozmowie.
 *
 * Uruchomienie z katalogu głównego repozytorium (PHP z obrazu aplikacji):
 *   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/work -w /work cinema/php:dev \
 *     php tools/readme-compliance/check.php "Etap 7"
 *
 * Wynik: lista BRAK, liczniki i EXIT. Strażnik pułapki AW: kod 0 wymaga ZERO braków,
 * zgodnej tabeli testów i niepustego wyniku (co najmniej MIN_CHECKED sprawdzonych nazw).
 *
 * Etap 9, blok O: także aplikacja mobilna — kod Darta (klasy, enumy, miksiny), zasoby Androida
 * i pliki konfiguracyjne Gradle'a, oraz tabela testów Fluttera porównywana z raportem
 * "flutter test --machine", który wykonawca paczek zostawia w FLUTTER_REPORT.
 * Brak raportu = BRAK, tak samo jak przy Viteście.
 *
 * Etap 8, blok O: także nazwy z frontu (frontend/src, frontend/public, package.json, konfiguracja
 * Vite i TypeScript), komponenty Vue i eksporty TypeScript jako "klasy", pliki po samej nazwie
 * (np. vHtmlGuard.spec.ts) oraz tabela testów Vitest porównywana z raportem JSON, który zapisuje
 * wykonawca paczek (VITEST_REPORT). Brak raportu = BRAK.
 *
 * Etap 10, blok A (pułapka EI): ścieżki raportów BEZ numeru etapu. Stała wskazywała katalog
 * .cache/etap8, a wykonawca Etapu 9 pisał już do .cache/etap9 — sprawdzacz dostawał "brak raportu"
 * dla całej tabeli Vitest i nikt tego nie widział, bo sekcji Etapu 8 nikt już nie sprawdzał.
 * Te same dwie ścieżki są w wykonawcy (ETAP9_VITEST_REPORT, ETAP9_FLUTTER_REPORT) i w CI;
 * test dymny bloku A porównuje je ze sobą, zamiast ufać, że ktoś pamięta o obu miejscach.
 */

const MIN_CHECKED = 100;
const VITEST_REPORT = 'frontend/node_modules/.cache/testy/vitest.json';
const FLUTTER_REPORT = 'mobile/build/testy/test.json';
const SEARCH_DIRS = ['backend/app', 'backend/config', 'backend/database', 'backend/routes', 'backend/resources',
    'backend/tests', 'backend/bootstrap', 'backend/public/js', 'backend/public/vendor/admin', 'docker', 'tools',
    'frontend/src', 'frontend/public', 'mobile/lib', 'mobile/test', 'mobile/android/app/src'];
const SEARCH_FILES = ['docker-compose.yml', 'backend/.env.example', 'backend/phpunit.xml', 'backend/composer.json',
    'frontend/package.json', 'frontend/vite.config.ts', 'frontend/tsconfig.json', 'frontend/index.html', '.gitattributes',
    'docker/secrets/.gitignore', 'mobile/pubspec.yaml', 'mobile/analysis_options.yaml', 'mobile/.gitignore',
    'mobile/android/app/build.gradle.kts', 'mobile/android/settings.gradle.kts', 'mobile/android/build.gradle.kts'];

$heading = $argv[1] ?? 'Etap 7';
$readme = (string) @file_get_contents('README.md');
if ($readme === '') {
    fwrite(STDERR, "STOP: brak README.md (uruchom z katalogu głównego repozytorium)\n");
    exit(2);
}

// 1. Sekcja etapu: od "## <heading>" do następnego nagłówka drugiego poziomu.
if (preg_match('/^## '.preg_quote($heading, '/').'\b.*?(?=^## |\z)/msu', $readme, $m) !== 1) {
    echo "STOP: brak sekcji \"## {$heading}\" w README.md\n", "EXIT: 1\n";
    exit(1);
}
$section = preg_replace('/^```.*?^```/msu', '', $m[0]);   // bloki kodu to diagramy, nie nazwy

// 2. Korpus do wyszukiwania: pliki tekstowe repozytorium (bez vendor i node_modules).
$corpus = [];
foreach (SEARCH_DIRS as $dir) {
    if (! is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        // Bez samego sprawdzacza (jego komentarze zawierają przykładowe nazwy) i bez zależności.
        // docker/secrets/: pliki z sekretami (konto serwisowe Firebase) nie są czytane nawet do pamięci.
        if (str_starts_with($path, 'tools/readme-compliance/') || str_starts_with($path, 'docker/secrets/')
            || (preg_match('#/(vendor|node_modules|dist)/#', $path) === 1 && ! str_starts_with($path, 'backend/public/vendor/admin'))) {
            continue;
        }
        if ($file->isFile() && $file->getSize() < 2_000_000 && preg_match('/\.(php|js|mjs|ts|vue|css|html|json|ini|conf|sh|yml|yaml|xml|md|txt|dart|kts|ps1)$|SHA256SUMS$|\.gitignore$/', $path) === 1) {
            $corpus[$path] = (string) file_get_contents($path);
        }
    }
}
foreach (SEARCH_FILES as $path) {
    if (is_file($path)) {
        $corpus[$path] = (string) file_get_contents($path);
    }
}
$anywhere = static fn (string $needle): bool => array_filter($corpus, static fn (string $text): bool => str_contains($text, $needle)) !== [];
// Całe słowo: SCREENING_HAS nie może "znaleźć się" w SCREENING_HAS_SALES, retry-refund w retry-refunds.
$anywhereWord = static function (string $needle) use ($corpus): bool {
    $pattern = '/(?<![A-Za-z0-9_:-])'.preg_quote($needle, '/').'(?![A-Za-z0-9_-])/';

    return array_filter($corpus, static fn (string $text): bool => preg_match($pattern, $text) === 1) !== [];
};
$classFile = static function (string $class) use ($corpus): ?string {
    foreach ($corpus as $path => $text) {
        if (str_ends_with($path, '.php') && preg_match('/^(?:final |abstract |readonly )*(?:class|enum|trait|interface) '.preg_quote($class, '/').'\b/m', $text) === 1) {
            return $path;
        }
        // Etap 8: komponent Vue (plik o tej nazwie) albo eksport TypeScript (klasa, interfejs, typ).
        if ((str_ends_with($path, '.vue') && basename($path, '.vue') === $class)
            || (str_ends_with($path, '.ts') && preg_match('/^export (?:default )?(?:abstract )?(?:class|interface|type|enum) '.preg_quote($class, '/').'\b/m', $text) === 1)) {
            return $path;
        }
        // Etap 9: Dart. Modyfikatory bywają łączone ("abstract interface class PushService",
        // "final class"), a enum i mixin są tu klasami tak samo jak class.
        if (str_ends_with($path, '.dart')
            && preg_match('/^(?:abstract |final |base |interface |sealed |mixin )*(?:class|enum|mixin) '.preg_quote($class, '/').'\b/m', $text) === 1) {
            return $path;
        }
    }

    return null;
};

// 3. Trasy i testy z aplikacji (bez bazy danych).
$routes = json_decode((string) shell_exec('php backend/artisan route:list --json 2>/dev/null'), true) ?: [];
$uris = [];
foreach ($routes as $route) {
    foreach (explode('|', (string) $route['method']) as $method) {
        $uris[] = strtoupper($method).' /'.ltrim((string) $route['uri'], '/');
    }
}
$listed = (string) shell_exec('cd backend && php vendor/bin/phpunit --list-tests 2>/dev/null');
$testCounts = [];
foreach (preg_split('/\R/', $listed) as $line) {
    if (preg_match('/^ - (?:[A-Za-z]+\\\\)*([A-Za-z0-9]+)::/', $line, $t) === 1) {
        $testCounts[$t[1]] = ($testCounts[$t[1]] ?? 0) + 1;
    }
}

$ok = 0;
$missing = [];
$pass = static function (string $kind, string $name, bool $found, string $why = '') use (&$ok, &$missing): void {
    if ($found) {
        $ok++;
    } else {
        $missing[] = sprintf('BRAK  %-8s %s%s', $kind, $name, $why === '' ? '' : ' ('.$why.')');
    }
};

// 4. Tabela testów: "| `KlasaTest` | N |" i wiersz "Razem".
$tableTotal = 0;
if (preg_match_all('/^\| `([A-Za-z0-9]+Test)` \| (\d+) \|/m', $section, $rows, PREG_SET_ORDER) > 0) {
    foreach ($rows as [, $class, $count]) {
        $tableTotal += (int) $count;
        $actual = $testCounts[$class] ?? 0;
        $pass('test', "{$class} = {$count}", $actual === (int) $count, "PHPUnit zbiera {$actual}");
    }
}
// Etap 8: tabela Vitest "| `plik.spec.ts` | N |" i wiersz "Razem Vitest" kontra raport JSON wykonawcy.
if (preg_match_all('/^\| `([A-Za-z0-9]+\.spec\.ts)` \| (\d+) \|/m', $section, $specRows, PREG_SET_ORDER) > 0) {
    $vitest = json_decode((string) @file_get_contents(VITEST_REPORT), true);
    $specCounts = [];
    foreach ((is_array($vitest) ? $vitest['testResults'] ?? [] : []) as $result) {
        $specCounts[basename((string) $result['name'])] = count($result['assertionResults'] ?? []);
    }
    $pass('vitest', 'raport '.VITEST_REPORT, $specCounts !== [], 'uruchom testy frontu przed sprawdzeniem');
    $specTotal = 0;
    foreach ($specRows as [, $file, $count]) {
        $specTotal += (int) $count;
        $pass('vitest', "{$file} = {$count}", ($specCounts[$file] ?? -1) === (int) $count, 'Vitest zbiera '.($specCounts[$file] ?? 0));
    }
    $unlisted = array_diff(array_keys($specCounts), array_column($specRows, 1));
    $pass('vitest', 'wszystkie pliki testów w tabeli', $unlisted === [], 'brak w tabeli: '.implode(', ', $unlisted));
    if (preg_match('/^\| \*\*Razem Vitest\*\* \| \*\*(\d+)\*\* \|/m', $section, $vsum) === 1) {
        $all = is_array($vitest) ? (int) ($vitest['numTotalTests'] ?? -1) : -1;
        $pass('vitest', 'Razem Vitest = '.$vsum[1], (int) $vsum[1] === $all && $specTotal === $all, "Vitest zbiera {$all}, tabela sumuje {$specTotal}");
    }
}
// Etap 9: tabela Fluttera "| `plik_test.dart` | N |" kontra raport "flutter test --machine".
// Format raportu to strumień zdarzeń JSON (jedno na linię), a nie jeden dokument: liczymy
// zakończone testy per plik, pomijając ukryte (ładowanie pliku testowego to też "test").
if (preg_match_all('/^\| `([a-z0-9_]+_test\.dart)` \| (\d+) \|/m', $section, $dartRows, PREG_SET_ORDER) > 0) {
    $dartCounts = [];
    $suites = $testNames = [];
    // Podział WYŁĄCZNIE po znaku nowej linii, nie przez \R: w PCRE bez modyfikatora /u
    // \R dopasowuje także bajt 0x85 (NEL), a ten jest drugim bajtem litery "ą" (C4 85).
    // Nazwy testów są po polsku, więc \R rozcinałby zdarzenia JSON w środku słowa,
    // json_decode odrzucał obie połówki i liczby wychodziły za małe (pułapka EE).
    $raport = str_replace("\r\n", "\n", (string) @file_get_contents(FLUTTER_REPORT));
    foreach (explode("\n", $raport) as $line) {
        if (! str_starts_with(trim($line), '{')) {
            continue;
        }
        $event = json_decode($line, true);
        if (! is_array($event)) {
            continue;
        }
        if (($event['type'] ?? '') === 'suite') {
            $suites[$event['suite']['id']] = basename((string) ($event['suite']['path'] ?? '?'));
        } elseif (($event['type'] ?? '') === 'testStart' && ! str_starts_with((string) ($event['test']['name'] ?? ''), 'loading ')) {
            $testNames[$event['test']['id']] = $suites[$event['test']['suiteID'] ?? -1] ?? '?';
        } elseif (($event['type'] ?? '') === 'testDone' && ($event['hidden'] ?? false) === false) {
            $file = $testNames[$event['testID']] ?? '?';
            $dartCounts[$file] = ($dartCounts[$file] ?? 0) + 1;
        }
    }
    $pass('flutter', 'raport '.FLUTTER_REPORT, $dartCounts !== [], 'uruchom testy aplikacji mobilnej przed sprawdzeniem');
    $dartTotal = 0;
    foreach ($dartRows as [, $file, $count]) {
        $dartTotal += (int) $count;
        $pass('flutter', "{$file} = {$count}", ($dartCounts[$file] ?? -1) === (int) $count, 'Flutter zbiera '.($dartCounts[$file] ?? 0));
    }
    $unlistedDart = array_diff(array_keys($dartCounts), array_column($dartRows, 1));
    $pass('flutter', 'wszystkie pliki testów w tabeli', $unlistedDart === [], 'brak w tabeli: '.implode(', ', $unlistedDart));
    if (preg_match('/^\| \*\*Razem Flutter\*\* \| \*\*(\d+)\*\* \|/m', $section, $dsum) === 1) {
        $allDart = array_sum($dartCounts);
        $pass('flutter', 'Razem Flutter = '.$dsum[1], (int) $dsum[1] === $allDart && $dartTotal === $allDart, "Flutter zbiera {$allDart}, tabela sumuje {$dartTotal}");
    }
}
if (preg_match('/^\| Etapy 1–\d+ \| (\d+) \|/mu', $section, $rest) === 1 && preg_match('/^\| \*\*Razem\*\* \| \*\*(\d+)\*\* \|/m', $section, $sum) === 1) {
    $all = array_sum($testCounts);
    $pass('test', 'Razem = '.$sum[1], (int) $sum[1] === $all && $tableTotal + (int) $rest[1] === $all, "PHPUnit zbiera {$all}, tabela sumuje ".($tableTotal + (int) $rest[1]));
}

// Etap 8: ścieżki tras SPA z routera Vue bez wyrażeń regularnych parametrów
// ('/screenings/:id(\\d+)/seats' -> '/screenings/:id/seats').
$spaPaths = [];
if (preg_match_all("/path: '([^']+)'/", $corpus['frontend/src/router/index.ts'] ?? '', $spa) > 0) {
    $spaPaths = array_map(static fn (string $path): string => (string) preg_replace('/\((?:[^()]|\([^()]*\))*\)/', '', $path), $spa[1]);
}

// 5. Nazwy w backtickach.
preg_match_all('/`([^`\n]+)`/u', $section, $tokens);
$seen = [];
foreach (array_unique($tokens[1]) as $token) {
    $token = trim($token);
    if ($token === '' || isset($seen[$token])) {
        continue;
    }
    $seen[$token] = true;

    if (str_starts_with($token, '/') && str_contains($token, '/:')) {
        $pass('trasa SPA', $token, in_array($token, $spaPaths, true));
        continue;
    }

    // Etap 8: sama nazwa pliku z repozytorium (np. vHtmlGuard.spec.ts, package.json).
    if (preg_match('#^[\w.-]+\.[a-z]{2,4}$#', $token) === 1
        && array_filter(array_keys($corpus), static fn (string $path): bool => basename($path) === $token) !== []) {
        $pass('plik', $token, true);
        continue;
    }

    if (preg_match('/^(GET|POST|PUT|PATCH|DELETE) (\/\S+)$/', $token, $r) === 1 || preg_match('/^(\/(?:api\/v1|admin)\S*)$/', $token, $r) === 1) {
        $uri = preg_replace('/\?.*$/', '', end($r));
        $method = count($r) === 3 ? $r[1] : null;
        $found = array_filter($uris, static fn (string $u): bool => $method === null ? str_ends_with($u, ' '.$uri) : $u === $method.' '.$uri) !== [];
        $pass('trasa', $token, $found);
    } elseif (preg_match('/^([A-Z][A-Za-z0-9]+)::([a-zA-Z_][A-Za-z0-9_]*)(?:\(\))?$/', $token, $c) === 1) {
        $file = $classFile($c[1]);
        // Klasa z repozytorium: metoda albo stała w jej pliku. Fasada lub klasa frameworka: to samo wywołanie w kodzie.
        $found = $file !== null
            ? preg_match('/function '.$c[2].'\(|const '.$c[2].'\b/', $corpus[$file]) === 1
            : $anywhere($c[1].'::'.$c[2]);
        $pass('metoda', $token, $found);
    } elseif (preg_match('/^[A-Z][a-z]+[A-Z][A-Za-z0-9]*$/', $token) === 1) {
        $pass('klasa', $token, $classFile($token) !== null);
    } elseif (preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $token) === 1) {
        $pass('kod/env', $token, $anywhereWord($token));
    } elseif (preg_match('/^cinema:[a-z:-]+$/', $token) === 1) {
        $pass('komenda', $token, $anywhereWord($token));
    } elseif (preg_match('#^[\w.-]+(/[\w.{}-]*)+$#', $token) === 1 && ! str_contains($token, '{')) {
        $path = rtrim($token, '/');
        $pass('ścieżka', $token, file_exists($path) || file_exists('backend/'.$path) || file_exists('backend/app/'.$path) || $anywhere($path));
    } elseif (preg_match('/^(->)?([a-zA-Z_][A-Za-z0-9_]*)\(\)$/', $token, $fn) === 1) {
        $pass('wywołanie', $token, $anywhere($fn[2].'('));
    } elseif (preg_match('/^([^{*]{4,})[{*]/', $token, $prefix) === 1) {
        // Wzorzec z miejscem na wartość (cinema:{id}, posters/{ulid}.jpg): stała część przed zmienną.
        $pass('wzorzec', $token, $anywhere($prefix[1]));
    } elseif (preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $token) === 1 && ! str_contains($token, '.php')) {
        // Ścieżka w JSON-ie odpowiedzi albo w konfiguracji: każdy człon jako klucz.
        $pass('klucze', $token, $anywhere($token) || file_exists($token) || file_exists('backend/'.$token)
            || array_filter(explode('.', $token), static fn (string $part): bool => ! $anywhere("'{$part}'")) === []);
    } else {
        // Pozostałe fragmenty (nazwy indeksów, kolumn, kanałów, opcji): dosłownie w kodzie,
        // a nazwy z samych znaków słowa — jako całe słowo.
        // Etap 8: także nazwa pliku z repozytorium (np. vHtmlGuard.spec.ts).
        $pass('fragment', $token, (preg_match('/^[A-Za-z0-9_]+$/', $token) === 1 ? $anywhereWord($token) : $anywhere($token))
            || array_filter(array_keys($corpus), static fn (string $path): bool => basename($path) === $token) !== []);
    }
}

echo "== Zgodność README ↔ kod: sekcja \"{$heading}\" ==\n";
echo $missing === [] ? '' : implode("\n", $missing)."\n";
printf("OK: %d, BRAK: %d, testy w PHPUnit: %d, trasy: %d\n", $ok, count($missing), array_sum($testCounts), count($routes));
$exit = ($missing === [] && $ok >= MIN_CHECKED && $testCounts !== [] && $routes !== []) ? 0 : 1;
if ($ok < MIN_CHECKED) {
    echo "STOP: sprawdzono tylko {$ok} nazw (minimum ".MIN_CHECKED.") — pusty albo niepełny raport nie jest zielony (pułapka AW)\n";
}
echo "EXIT: {$exit}\n";
exit($exit);
