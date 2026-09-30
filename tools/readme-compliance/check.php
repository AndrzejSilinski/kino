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
 *     php tools/readme-compliance/check.php                 wszystkie sekcje (tak robi wykonawca i CI)
 *   ... php tools/readme-compliance/check.php "Etap 7"      jedna sekcja (diagnostyka)
 *
 * Wynik: lista BRAK, liczniki i EXIT. Strażnik pułapki AW: kod 0 wymaga ZERO braków
 * i niepustego wyniku — co najmniej MIN_CHECKED_SEKCJA nazw w każdej sekcji etapu
 * i MIN_CHECKED łącznie.
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
 *
 * Etap 10, blok A2 (decyzje 370–376): WSZYSTKIE sekcje, bo sprawdzana była tylko bieżąca, a starsze
 * po cichu przestały być prawdą (159 braków w etapach 2–8, pułapka EI).
 *   - Tabele testów w sekcjach etapów to STAN NA KONIEC ETAPU: sprawdzamy, że każda klasa i plik
 *     nadal istnieją i że wiersze sumują się do "Razem", ale nie liczby wobec dzisiejszego kodu.
 *     Dzisiejsze liczby są w jednej żywej tabeli w sekcji TESTY_HEADING, sprawdzanej dokładnie,
 *     razem z pokryciem: każda klasa testów i każdy plik testów musi być w README wymieniony.
 *   - `backticki` znaczą NAZWĘ z kodu i są sprawdzane; <code>…</code> znaczy przykład, składnię
 *     SQL albo polecenie powłoki i sprawdzane nie jest. Autor README deklaruje to w miejscu pisania;
 *     sprawdzacz nie zgaduje po wyglądzie, bo zgadywanie przepuszczałoby prawdziwe literówki.
 *   - Klasy frameworka i bibliotek szukamy w backend/vendor, klasy wbudowane PHP przez refleksję.
 *   - Ścieżki sprawdzamy wobec `git ls-files`, a nie file_exists: plik, który istnieje tylko
 *     lokalnie (np. dowiązanie public/storage z instrukcji uruchomienia), w CI go nie ma (pułapka EL).
 */

const MIN_CHECKED = 100;
const MIN_CHECKED_SEKCJA = 20;
const TESTY_HEADING = 'Testy — stan obecny';
const VITEST_REPORT = 'frontend/node_modules/.cache/testy/vitest.json';
const FLUTTER_REPORT = 'mobile/build/testy/test.json';
const SEARCH_DIRS = ['backend/app', 'backend/config', 'backend/database', 'backend/routes', 'backend/resources',
    'backend/tests', 'backend/bootstrap', 'backend/public/js', 'backend/public/vendor/admin', 'docker', 'tools',
    'frontend/src', 'frontend/public', 'mobile/lib', 'mobile/test', 'mobile/android/app/src'];
const SEARCH_FILES = ['docker-compose.yml', 'backend/.env.example', 'backend/phpunit.xml', 'backend/composer.json',
    'backend/composer.lock', 'frontend/package.json', 'frontend/vite.config.ts', 'frontend/tsconfig.json',
    'frontend/index.html', '.gitattributes', 'docker/secrets/.gitignore', 'mobile/pubspec.yaml',
    'mobile/analysis_options.yaml', 'mobile/.gitignore', 'mobile/android/app/build.gradle.kts',
    'mobile/android/settings.gradle.kts', 'mobile/android/build.gradle.kts'];

// README_PATH: inna kopia README (test dymny sprawdza zmutowane kopie — czy sprawdzacz WYKRYWA błędy).
$readmePath = getenv('README_PATH') ?: 'README.md';
$readme = (string) @file_get_contents($readmePath);
if ($readme === '') {
    fwrite(STDERR, "STOP: brak {$readmePath} (uruchom z katalogu głównego repozytorium)\n");
    exit(2);
}
// Bloki kodu to diagramy i przykłady, nie nazwy — wycinamy je raz, dla całego pliku.
$readmeBezKodu = (string) preg_replace('/^```.*?^```/msu', '', $readme);

// 1. Które sekcje: argument = jedna sekcja, bez argumentu = wszystkie "## Etap N" i sekcja testów.
if (isset($argv[1])) {
    $headings = [$argv[1]];
} else {
    preg_match_all('/^## (Etap \d+)\b/mu', $readme, $h);
    $headings = [...$h[1], TESTY_HEADING];
}
$sections = [];
foreach ($headings as $heading) {
    if (preg_match('/^## '.preg_quote($heading, '/').'\b.*?(?=^## |\z)/msu', $readmeBezKodu, $m) !== 1) {
        echo "STOP: brak sekcji \"## {$heading}\" w README.md\n", "EXIT: 1\n";
        exit(1);
    }
    $sections[$heading] = $m[0];
}

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
        // Etap 10: także Dockerfile (bez rozszerzenia) — pakiety obrazu, np. zbar, to nazwy z repozytorium.
        if ($file->isFile() && $file->getSize() < 2_000_000
            && preg_match('/\.(php|js|mjs|ts|vue|css|html|json|ini|conf|sh|yml|yaml|xml|md|txt|dart|kts|ps1)$|SHA256SUMS$|\.gitignore$|(^|\/)Dockerfile$/', $path) === 1) {
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

// Etap 10, blok A2: klasy spoza repozytorium. Najpierw wbudowane w PHP (TypeError, Throwable),
// potem backend/vendor — PSR-4 znaczy, że plik nazywa się jak klasa, więc indeksujemy same
// nazwy plików (raz), a czytamy tylko pliki o pasującej nazwie.
$vendorIndex = null;
$externalClass = static function (string $class) use (&$vendorIndex): bool {
    $class = ltrim($class, '\\');
    $short = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;
    if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false) || enum_exists($class, false)) {
        return true;
    }
    if ($vendorIndex === null) {
        $vendorIndex = [];
        if (is_dir('backend/vendor')) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('backend/vendor', FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $vendorIndex[basename($file->getFilename(), '.php')][] = $file->getPathname();
                }
            }
        }
    }
    foreach ($vendorIndex[$short] ?? [] as $path) {
        $text = (string) file_get_contents($path);
        $ns = str_contains($class, '\\') ? preg_quote(substr($class, 0, (int) strrpos($class, '\\')), '/') : null;
        if (preg_match('/^(?:final |abstract |readonly )*(?:class|enum|trait|interface) '.preg_quote($short, '/').'\b/m', $text) === 1
            && ($ns === null || preg_match('/^namespace (?:[\w\\\\]*\\\\)?'.$ns.';/m', $text) === 1)) {
            return true;
        }
    }

    return false;
};

// Etap 10, blok A2: pliki śledzone przez gita — jedyna lista plików, która jest taka sama u mnie i w CI.
$tracked = array_flip(array_filter(explode("\n", (string) shell_exec('git ls-files 2>/dev/null'))));
if ($tracked === []) {
    echo "STOP: pusta lista `git ls-files` (brak gita albo repozytorium w kontenerze?) — ścieżek nie da się sprawdzić\n", "EXIT: 1\n";
    exit(1);
}
$trackedPrefix = static function (string $path) use ($tracked): bool {
    if (isset($tracked[$path])) {
        return true;
    }
    foreach ($tracked as $file => $_) {
        if (str_starts_with((string) $file, rtrim($path, '/').'/')) {
            return true;   // katalog, w którym są śledzone pliki
        }
    }

    return false;
};
// Rozwinięcie nawiasów jak w powłoce: SeatLock{Service,Expiry}Test.php -> dwie ścieżki.
$expandBraces = static function (string $text) use (&$expandBraces): array {
    if (preg_match('/^(.*?)\{([^{}]*,[^{}]*)\}(.*)$/', $text, $b) !== 1) {
        return [$text];
    }
    $out = [];
    foreach (explode(',', $b[2]) as $part) {
        array_push($out, ...$expandBraces($b[1].$part.$b[3]));
    }

    return $out;
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
$testClassesFq = [];
foreach (explode("\n", $listed) as $line) {
    if (preg_match('/^ - ((?:[A-Za-z0-9]+\\\\)*([A-Za-z0-9]+))::/', $line, $t) === 1) {
        $testCounts[$t[2]] = ($testCounts[$t[2]] ?? 0) + 1;
        $testClassesFq[$t[1]] = true;
    }
}

// Raporty frontu i Fluttera: potrzebne TYLKO żywej tabeli (sekcja TESTY_HEADING).
$vitest = json_decode((string) @file_get_contents(VITEST_REPORT), true);
$specCounts = [];
foreach ((is_array($vitest) ? $vitest['testResults'] ?? [] : []) as $result) {
    $specCounts[basename((string) $result['name'])] = count($result['assertionResults'] ?? []);
}
// Format raportu Fluttera to strumień zdarzeń JSON (jedno na linię), a nie jeden dokument: liczymy
// zakończone testy per plik, pomijając ukryte (ładowanie pliku testowego to też "test").
// Podział WYŁĄCZNIE po znaku nowej linii, nie przez \R: w PCRE bez modyfikatora /u
// \R dopasowuje także bajt 0x85 (NEL), a ten jest drugim bajtem litery "ą" (C4 85).
// Nazwy testów są po polsku, więc \R rozcinałby zdarzenia JSON w środku słowa (pułapka EE).
$dartCounts = [];
$suites = $testNames = [];
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
$trackedBasename = static function (string $name) use ($tracked): bool {
    foreach ($tracked as $file => $_) {
        if (basename((string) $file) === $name) {
            return true;
        }
    }

    return false;
};

// Etap 8: ścieżki tras SPA z routera Vue bez wyrażeń regularnych parametrów
// ('/screenings/:id(\\d+)/seats' -> '/screenings/:id/seats').
$spaPaths = [];
if (preg_match_all("/path: '([^']+)'/", $corpus['frontend/src/router/index.ts'] ?? '', $spa) > 0) {
    $spaPaths = array_map(static fn (string $path): string => (string) preg_replace('/\((?:[^()]|\([^()]*\))*\)/', '', $path), $spa[1]);
}

// Sprawdzenie jednej sekcji: zwraca [liczba OK, lista BRAK].
$checkSection = static function (string $heading, string $section) use (
    $anywhere, $anywhereWord, $classFile, $externalClass, $trackedPrefix, $trackedBasename, $expandBraces,
    $uris, $testCounts, $testClassesFq, $vitest, $specCounts, $dartCounts, $spaPaths, $corpus, $readmeBezKodu
): array {
    $ok = 0;
    $missing = [];
    $pass = static function (string $kind, string $name, bool $found, string $why = '') use (&$ok, &$missing): void {
        if ($found) {
            $ok++;
        } else {
            $missing[] = sprintf('BRAK  %-8s %s%s', $kind, $name, $why === '' ? '' : ' ('.$why.')');
        }
    };

    if ($heading === TESTY_HEADING) {
        // 4a. Żywa tabela: dzisiejsze liczby, dokładnie.
        $phpTotal = array_sum($testCounts);
        $pass('raport', 'lista testów PHPUnit', $testCounts !== [], 'phpunit --list-tests nic nie zwrócił');
        $pass('raport', 'raport '.VITEST_REPORT, $specCounts !== [], 'uruchom testy frontu przed sprawdzeniem');
        $pass('raport', 'raport '.FLUTTER_REPORT, $dartCounts !== [], 'uruchom testy aplikacji mobilnej przed sprawdzeniem');
        $rows = [
            'PHPUnit' => [$phpTotal, count($testClassesFq), 'klas', 'klas'],
            'Vitest' => [is_array($vitest) ? (int) ($vitest['numTotalTests'] ?? -1) : -1, count($specCounts), 'plik', 'plików'],
            'Flutter' => [array_sum($dartCounts), count($dartCounts), 'plik', 'plików'],
        ];
        foreach ($rows as $suite => [$tests, $files, $unit, $label]) {
            if (preg_match('/^\| '.$suite.'[^|]*\| \*\*(\d+)\*\* \| (\d+) '.$unit.'/mu', $section, $r) === 1) {
                $pass('żywa', "{$suite}: testów {$r[1]}", (int) $r[1] === $tests, "zbiera {$tests}");
                $pass('żywa', "{$suite}: {$label} {$r[2]}", (int) $r[2] === $files, "zbiera {$files}");
            } else {
                $pass('żywa', "{$suite}: wiersz tabeli", false, 'brak wiersza "| '.$suite.' ... | **N** | M '.$unit.'..."');
            }
        }
        // 4b. Pokrycie: każda klasa i każdy plik testów wymieniony gdziekolwiek w README (poza blokami
        // kodu). Nowy test bez słowa opisu nie przejdzie — ani test opisany, którego już nie ma (4c).
        preg_match_all('/`([^`\n]+)`/u', $readmeBezKodu, $all);
        $mentioned = [];
        foreach ($all[1] as $token) {
            foreach ($expandBraces(trim($token)) as $name) {
                $mentioned[basename($name, '.php')] = true;
                $mentioned[basename($name)] = true;
            }
        }
        foreach (array_keys($testCounts) as $class) {
            $pass('pokrycie', "klasa testów {$class}", isset($mentioned[$class]), 'nie występuje w README');
        }
        foreach (array_keys($specCounts) as $file) {
            $pass('pokrycie', "plik testów {$file}", isset($mentioned[$file]), 'nie występuje w README');
        }
        foreach (array_keys($dartCounts) as $file) {
            $pass('pokrycie', "plik testów {$file}", isset($mentioned[$file]), 'nie występuje w README');
        }
    } else {
        // 4c. Tabele testów etapu = stan na koniec etapu: klasa lub plik nadal istnieje, a wiersze
        // sumują się do "Razem" (literówka w sumie historycznej też jest błędem README).
        // Wiersze z dopiskiem, np. "`RetryPolicyTest` (Unit) | 4", do Etapu 9 były pomijane W CAŁOŚCI
        // (wzorzec wymagał "|" zaraz po nazwie) — tabela Etapu 5 "sumowała się" do 123 zamiast 141.
        $tables = [
            'test' => ['/^\| `([A-Za-z0-9]+Test)`(?: \([^)|]*\))? \| (\d+) \|/m', '/^\| \*\*Razem\*\* \| \*\*(\d+)\*\* \|/m',
                static fn (string $c): bool => ($testCounts[$c] ?? 0) > 0, 'PHPUnit nie zbiera tej klasy'],
            'vitest' => ['/^\| `([A-Za-z0-9]+\.spec\.ts)` \| (\d+) \|/m', '/^\| \*\*Razem Vitest\*\* \| \*\*(\d+)\*\* \|/m',
                $trackedBasename, 'brak pliku w repozytorium'],
            'flutter' => ['/^\| `([a-z0-9_]+_test\.dart)` \| (\d+) \|/m', '/^\| \*\*Razem Flutter\*\* \| \*\*(\d+)\*\* \|/m',
                $trackedBasename, 'brak pliku w repozytorium'],
        ];
        foreach ($tables as $kind => [$rowRe, $sumRe, $exists, $why]) {
            if (preg_match_all($rowRe, $section, $rows, PREG_SET_ORDER) === 0) {
                continue;
            }
            $sum = 0;
            foreach ($rows as [, $name, $count]) {
                $sum += (int) $count;
                $pass($kind, "{$name} istnieje", $exists($name), $why);
            }
            if ($kind === 'test' && preg_match('/^\| Etapy 1–\d+ \| (\d+) \|/mu', $section, $rest) === 1) {
                $sum += (int) $rest[1];
            }
            if (preg_match($sumRe, $section, $s) === 1) {
                $pass($kind, 'Razem = '.$s[1].' (stan na koniec etapu)', (int) $s[1] === $sum, "wiersze sumują się do {$sum}");
            }
        }
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
        if (preg_match('#^[\w.-]+\.[a-z]{2,4}$#', $token) === 1 && $trackedBasename($token)) {
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
            // Klasa z repozytorium: metoda, stała albo przypadek enuma (Etap 10: UserRole::Customer)
            // w jej pliku. Fasada lub klasa frameworka: to samo wywołanie w kodzie.
            $found = $file !== null
                ? preg_match('/function '.$c[2].'\(|const '.$c[2].'\b|case '.$c[2].'\b/', $corpus[$file]) === 1
                : $anywhere($c[1].'::'.$c[2]);
            $pass('metoda', $token, $found);
        } elseif (preg_match('/^\\\\?(?:[A-Z][A-Za-z0-9]*\\\\)*[A-Z][a-z]+[A-Z][A-Za-z0-9]*$/', $token) === 1
            || preg_match('/^\\\\?(?:[A-Z][A-Za-z0-9]*\\\\)+[A-Z][A-Za-z0-9]*$|^\\\\[A-Z][A-Za-z0-9]*$/', $token) === 1) {
            // Klasa: z repozytorium, wbudowana w PHP albo z backend/vendor (Etap 10: FormRequest,
            // RefreshDatabase, Pusher\ApiErrorException, \Throwable).
            $short = str_contains($token, '\\') ? substr($token, (int) strrpos($token, '\\') + 1) : $token;
            // Przestrzeń nazw (App\Events) nie jest klasą — wystarczy, że występuje w kodzie.
            $pass('klasa', $token, $classFile($short) !== null || $externalClass($token)
                || (str_contains($token, '\\') && $anywhere(ltrim($token, '\\'))));
        } elseif (preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $token) === 1) {
            $pass('kod/env', $token, $anywhereWord($token));
        } elseif (preg_match('/^cinema:[a-z:-]+$/', $token) === 1) {
            $pass('komenda', $token, $anywhereWord($token));
        } elseif (preg_match('#^[\w.-]+(/[\w.{},-]*)+$#', $token) === 1 && ! str_contains($token, '{') || preg_match('#^[\w.-]+(/[\w.-]*)*/?[\w.-]*\{[\w.-]+(,[\w.-]+)+\}[\w./-]*$#', $token) === 1) {
            // Ścieżka (Etap 10: wobec git ls-files, z rozwinięciem {a,b} jak w powłoce i z domyślnym .php).
            $found = true;
            foreach ($expandBraces(rtrim($token, '/')) as $path) {
                $found = $found && ($trackedPrefix($path) || $trackedPrefix('backend/'.$path) || $trackedPrefix('backend/app/'.$path)
                    || $trackedPrefix($path.'.php') || $trackedPrefix('backend/'.$path.'.php') || $anywhere($path));
            }
            $pass('ścieżka', $token, $found);
        } elseif (preg_match('/^(->)?([a-zA-Z_][A-Za-z0-9_]*)\(\)$/', $token, $fn) === 1) {
            $pass('wywołanie', $token, $anywhere($fn[2].'('));
        } elseif (preg_match('/^([^{*]{4,})[{*]/', $token, $prefix) === 1) {
            // Wzorzec z miejscem na wartość (cinema:{id}, posters/{ulid}.jpg): stała część przed zmienną.
            $pass('wzorzec', $token, $anywhere($prefix[1]));
        } elseif (preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $token) === 1 && ! str_contains($token, '.php')) {
            // Ścieżka w JSON-ie odpowiedzi albo w konfiguracji: każdy człon jako klucz.
            $pass('klucze', $token, $anywhere($token) || $trackedPrefix($token) || $trackedPrefix('backend/'.$token)
                || array_filter(explode('.', $token), static fn (string $part): bool => ! $anywhere("'{$part}'")) === []);
        } else {
            // Pozostałe fragmenty (nazwy indeksów, kolumn, kanałów, opcji): dosłownie w kodzie,
            // a nazwy z samych znaków słowa — jako całe słowo. Etap 10: metoda wołana przez
            // "::" albo "->" (afterCommit w DB::afterCommit) też jest nazwą z kodu.
            $pass('fragment', $token, (preg_match('/^[A-Za-z0-9_]+$/', $token) === 1 ? $anywhereWord($token) : $anywhere($token))
                || (preg_match('/^[a-z][A-Za-z0-9_]*$/', $token) === 1 && ($anywhere('::'.$token.'(') || $anywhere('->'.$token.'(')))
                || $trackedBasename($token));
        }
    }

    return [$ok, $missing];
};

$okAll = 0;
$missingAll = 0;
$tooFew = [];
foreach ($sections as $heading => $section) {
    [$ok, $missing] = $checkSection($heading, $section);
    $okAll += $ok;
    $missingAll += count($missing);
    echo "== Zgodność README ↔ kod: sekcja \"{$heading}\" ==\n";
    echo $missing === [] ? '' : implode("\n", $missing)."\n";
    $floor = $heading === TESTY_HEADING ? 1 : MIN_CHECKED_SEKCJA;
    if ($ok < $floor) {
        $tooFew[] = $heading;
        echo "STOP: w sekcji \"{$heading}\" sprawdzono tylko {$ok} nazw (minimum {$floor}) — pusty wynik nie jest zielony (pułapka AW)\n";
    }
    printf("WYNIK sekcji: OK %d, BRAK %d\n", $ok, count($missing));
}
echo "== Razem ==\n";
printf("OK: %d, BRAK: %d, sekcji: %d, testy w PHPUnit: %d, trasy: %d\n", $okAll, $missingAll, count($sections), array_sum($testCounts), count($routes));
$minTotal = count($sections) > 1 ? MIN_CHECKED : MIN_CHECKED_SEKCJA;
if ($okAll < $minTotal) {
    echo "STOP: sprawdzono tylko {$okAll} nazw (minimum {$minTotal}) — pusty albo niepełny raport nie jest zielony (pułapka AW)\n";
}
$exit = ($missingAll === 0 && $tooFew === [] && $okAll >= $minTotal && $testCounts !== [] && $routes !== []) ? 0 : 1;
echo "EXIT: {$exit}\n";
exit($exit);
