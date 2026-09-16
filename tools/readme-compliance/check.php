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
 */

const MIN_CHECKED = 100;
const SEARCH_DIRS = ['backend/app', 'backend/config', 'backend/database', 'backend/routes', 'backend/resources',
    'backend/tests', 'backend/bootstrap', 'backend/public/js', 'backend/public/vendor/admin', 'docker', 'tools'];
const SEARCH_FILES = ['docker-compose.yml', 'backend/.env.example', 'backend/phpunit.xml', 'backend/composer.json'];

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
        if (str_starts_with($path, 'tools/readme-compliance/')
            || (preg_match('#/(vendor|node_modules)/#', $path) === 1 && ! str_starts_with($path, 'backend/public/vendor/admin'))) {
            continue;
        }
        if ($file->isFile() && $file->getSize() < 2_000_000 && preg_match('/\.(php|js|json|ini|conf|sh|yml|yaml|xml|md|txt)$|SHA256SUMS$/', $path) === 1) {
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
if (preg_match('/^\| Etapy 1–\d+ \| (\d+) \|/mu', $section, $rest) === 1 && preg_match('/^\| \*\*Razem\*\* \| \*\*(\d+)\*\* \|/m', $section, $sum) === 1) {
    $all = array_sum($testCounts);
    $pass('test', 'Razem = '.$sum[1], (int) $sum[1] === $all && $tableTotal + (int) $rest[1] === $all, "PHPUnit zbiera {$all}, tabela sumuje ".($tableTotal + (int) $rest[1]));
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
        $pass('fragment', $token, preg_match('/^[A-Za-z0-9_]+$/', $token) === 1 ? $anywhereWord($token) : $anywhere($token));
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
