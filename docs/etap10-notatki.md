# Etap 10 — notatki robocze: decyzje i pułapki

Plik prowadzony **na bieżąco, blok po bloku**, tak jak w Etapie 9. Każda paczka bloku dopisuje tu
swoje decyzje i pułapki, a blok README tylko je redaguje do sekcji Etapu 10 w `README.md`.

Numeracja ciągła z poprzednich etapów: **decyzje od 361**, **pułapki od EH**.

Pułapka ma trzy części: objaw, przyczynę i nauczkę ogólniejszą niż sam błąd.

---

## Rozpoznanie przed Etapem 10

Trzy skrypty tylko do odczytu (`etap10_rozpoznanie.sh`, `etap10_A_rozpoznanie.sh`,
`etap10_A_baza.sh`) i jeden do wyjątków gitleaks (`etap10_A_wartosci.sh`). Wszystkie działały
jako ten sam użytkownik co wykonawca paczek, maskowały sekrety i na końcu skanowały własny raport.

### Zmierzone (stan wyjściowy, HEAD `45b7e95`)

- **Narzędzia w WSL:** docker 29.7.2 (Compose v5.3.1), git, openssl, jq, curl, perl. **Nie ma:**
  php, composer, node, flutter, java/keytool, mkcert, gh, act, gitleaks. Wszystko, czego
  brakuje, działa w kontenerach przypiętych po digeście — i tak samo ma działać w CI.
- **Po stronie Windows:** `adb` jest (`%USERPROFILE%\platform-tools\adb.exe`, 37.0.1), tylko
  poza `PATH`; `telefon.ps1` szuka go właśnie tam.
- **Wersje:** PHP 8.4.25, Laravel 13.26.0, Reverb 1.11.1, stripe-php 21.3.2, Livewire 4.4.5,
  Sanctum 4.3.3, Scramble 0.13.43.
- **Obrazy bez przypięcia:** `php:8.4-fpm-alpine`, `composer:2`, `nginx:1.27-alpine`,
  `postgres:16-alpine`, `redis:7-alpine`, `axllent/mailpit:latest` (blok D).
- **gitleaks v8.30.1 na całej historii (70 commitów):** 3 znaleziska `generic-api-key`, wszystkie
  w testach PHP i wszystkie będące atrapami (decyzja 364). Niezależna miara — nazwy plików
  dodanych kiedykolwiek do historii — nie znalazła ani jednego pliku wrażliwego.
- **Sprawdzacz zgodności README, każda sekcja „## Etap N”:** przechodzi **tylko Etap 9**.
  Etapy 2–8: 159 braków (Etap 2: 17, 3: 17, 4: 4, 5: 31, 6: 30, 7: 2, 8: 58), a etapy 2–4
  nie osiągają nawet minimum 100 sprawdzonych nazw. Rodzaje: zła ścieżka raportu Vitest (25,
  pułapka EI), liczby testów w klasach, do których późniejsze etapy dopisały testy (ok. 9),
  kilka nazw, których dziś nie ma w kodzie (np. `UserRole::Customer`), oraz ok. 120 fałszywych
  alarmów: klasy frameworka z `vendor/`, SQL i polecenia powłoki pisane w tekście (blok A2).
- **`backend/.env.example` a działający `.env`:** przykład ma `DB_CONNECTION=sqlite`,
  `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`,
  `MAIL_MAILER=log` i nie ma `DB_HOST`, `DB_PASSWORD` ani `REDIS_QUEUE_RETRY_AFTER`, na którym
  polega komentarz workera w `docker-compose.yml`. Lokalny `.env` ma `pgsql`, `redis` i `smtp`.
  Wniosek do potwierdzenia w CI (świeże środowisko z samego `.env.example`): „Uruchomienie od zera”
  z README dziś najpewniej nie działa (blok B).

### Decyzje

**361. Zakres Etapu 10 wyznaczają obietnice z README — wyszukane wzorcem, który zna odmianę.**
Pierwsze rozpoznanie szukało napisu „Etap 10” i przeoczyło cztery obietnice zapisane jako
„w Etapie 10”, „do Etapu 10”: entrypoint kontenera zamiast kroków ręcznych po
`docker compose up` (Etapy 2, 5 i „Uruchomienie od zera”), sondę WebSocket uruchamianą jednym
poleceniem w CI (Etap 6), listę platform Androida wpisaną na stałe do `docker/flutter/Dockerfile`
(decyzja 271) i „scenariusze e2e do CI” (Etap 8). Ostatnia stoi w sprzeczności z wykluczeniem
Playwrighta z Etapu 10 — rozwiązanie: CI uruchamia sondę czasu rzeczywistego, a README uczciwie
mówi, że testów e2e przeglądarki nie ma, zamiast przemilczeć złożoną obietnicę. Pułapka EH.

**362. Kolejność bloków: A wykonawca i gitleaks → A2 sprawdzacz README dla wszystkich sekcji →
B porządki i `.env.example` → C drobne długi → D obraz wieloetapowy i entrypoint → E TLS →
F GitHub Actions → G podpis release APK → H README.** Narzędzie, którym weryfikujemy każdy blok,
idzie pierwsze. Obraz (D) przed CI (F), bo CI ma testować ten sam obraz, który trafia na
produkcję — CI napisane wcześniej trzeba by przepisać po D i po E. Odrzucone: CI na samym
początku jako siatka bezpieczeństwa; do bloku F tę rolę pełni wykonawca paczek, który uruchamia
ten sam komplet testów.

**363. Wykonawca paczek zostaje jeden: `etap9_blok.sh`, zmieniony łatką dokładnych zamian.**
Prefiks paczek jest w jednej zmiennej (`ETAP9_PREFIX=etap10`), a nie w dziewięciu miejscach.
Instalację wykonuje skrypt, który podmienia plik TYLKO wtedy, gdy na pulpicie leży dokładnie
łatana wersja (suma przed), zostawia kopię `*_przed_etap10` i sprawdza sumę po. Samotest
wykonawcy (29 sprawdzeń) ma przypadki w obie strony — ścieżki, które MAJĄ przejść, i takie,
które MAJĄ zostać odrzucone — bo sprawdzenie bez przypadków pozytywnych nie odróżnia działania
od awarii (nauczka pułapki ED).

**364. gitleaks jako skaner sekretów: obraz przypięty po digeście, TEN SAM lokalnie i w CI;
wyjątki po dokładnej WARTOŚCI.** Obraz `zricethezav/gitleaks@sha256:c00b6bd0…bb7f` (v8.30.1).
Gotowa akcja `gitleaks-action` odrzucona: inny sposób uruchomienia niż u mnie, a dla
repozytoriów organizacji wymaga licencji. Trzy atrapy z testów (`sekret-reverb-…`,
`test-sekret-reverb-…`, `0123456789abcdef`×4) wyciszone w `.gitleaks.toml` wyrażeniami
zakotwiczonymi `^…$`, tylko dla reguły `generic-api-key`. Odrzucone:
- wyłączenie reguły `generic-api-key` — ślepota na cały typ kluczy w całym repozytorium,
- pominięcie plików testów — prawdziwy klucz wklejony do testu przeszedłby,
- odciski w `.gitleaksignore` — zawierają numer linii i commit; znalezisko z
  `ClientConfigApiTest.php:43` ma DZIŚ w linii 43 inną treść niż w commicie `b12dbc2`, więc taki
  wyjątek już raz by się rozsypał, a dokumentacja nazywa tę funkcję eksperymentalną.
Sprawdzone na tej samej wersji (binarka z sumą zgodną z listą sum wydania) w obie strony:
atrapy bez konfiguracji 3, z konfiguracją 0, NOWY klucz w tym samym stylu z konfiguracją 1.

**365. W wykonawcy gitleaks skanuje KOPIĘ zmian bloku i raportów, a nie drzewo roboczego
repozytorium** (krok 14b, `gitleaks dir`). Skan całego drzewa czytałby `backend/.env`, `vendor/`
i `docker/secrets/`, czyli zgłaszałby sekrety, które MAJĄ tam leżeć — i zamieniał się w alarm,
który każdy uczy się ignorować. Historię gita w całości skanuje CI (blok F). Dotychczasowy skan
wzorcami i dosłownymi wartościami z `.env` zostaje obok: gitleaks nie zna wartości z mojego `.env`.

**366. Raporty testów pod ścieżkami bez numeru etapu, kasowane na starcie przebiegu.**
`frontend/node_modules/.cache/testy/vitest.json` i `mobile/build/testy/test.json` — w wykonawcy
(`ETAP9_VITEST_REPORT`, `ETAP9_FLUTTER_REPORT`) i w `tools/readme-compliance/check.php`, a test
dymny porównuje oba miejsca ze sobą. Kasowanie na starcie jest wąskie (dwa pliki, nie katalogi):
brak przebiegu testów ma dawać „brak raportu”, a nie porównanie tabeli z raportem sprzed zmian.
Pułapka EI.

**367. Sprawdzacz README jest obowiązkowym krokiem każdego bloku (12b, pole `README_ETAPY`),
a docelowo sprawdza WSZYSTKIE sekcje etapów.** Wybór Andrzeja spośród trzech wariantów:
tylko bieżący etap (tanio, ale reszta dalej gnije — dokładnie to zmierzyliśmy), wszystkie sekcje
z liczbami testów podciągniętymi do dzisiejszych (historia „ile testów dał etap” traci sens) oraz
wybrany: wszystkie sekcje, tabele testów etapów zamrożone jako „stan na koniec etapu” i jedna
żywa tabela sprawdzana w całości. Wymaga nauczenia sprawdzacza klas z `vendor/` i odróżniania
SQL i poleceń powłoki — osobny blok A2. Do tego czasu bloki sprawdzają Etap 9.

**368. Lustro repozytorium dla Claude'a przez `git bundle`.** Łatki dokładnych zamian muszą
powstawać na bajtach identycznych z repozytorium Andrzeja. Bundle niesie tylko pliki śledzone
przez gita (bez `.env` i sekretów), a jego zgodność sprawdza `git bundle verify` i suma pliku.

**369. Nowe repozytorium na GitHubie dla CI.** Trafi tam cała historia — przeskanowana w
rozpoznaniu: poza trzema atrapami (decyzja 364) nic, a żaden plik wrażliwy nigdy nie był
dodany do gita. Widoczność (publiczne czy prywatne) ustalamy w bloku F.

### Pułapki

**EH. Szukanie obietnic po dosłownym napisie zawodzi w języku z odmianą.** Objaw: rozpoznanie
znalazło sześć wzmianek o Etapie 10 i na ich podstawie powstał plan bloków. Przyczyna:
`grep "Etap 10"` nie widzi „w Etapie 10” ani „do Etapu 10”, a tak jest zapisana połowa obietnic,
w tym najważniejsza — entrypoint zamiast kroków ręcznych. Nauczka: wzorzec ROZPOZNANIA też jest
testem i też trzeba go sprawdzić na tekście, który MA go spełniać; w polskim tekście szukamy
rdzenia (`Etap(ie|u|em)? 10`), a nie formy z nagłówka.

**EI. Ścieżka wymiany danych wpisana na sztywno w dwóch narzędziach rozjeżdża się przy pierwszej
zmianie nazwy.** Objaw: sprawdzacz README dla Etapu 8 zgłasza 25 braków — cała tabela Vitest
„zbiera 0”. Przyczyna: stała w `check.php` wskazywała `.cache/etap8/vitest.json`, a wykonawca
Etapu 9 zapisywał raport do `.cache/etap9/`; ostatni plik pochodził z 17 września. Nikt tego nie
zauważył, bo sprawdzacz uruchamialiśmy tylko dla bieżącego etapu — sekcja Etapu 8 po jego
zamknięciu nie była sprawdzana wcale, podobnie jak etapy 2–7 (159 braków łącznie). Dwie nauczki:
nazwa, którą znają dwa narzędzia, ma nie zawierać rzeczy zmiennych (numeru etapu), a zgodność
obu miejsc sprawdza test, nie pamięć; oraz narzędzie kontrolne uruchamiane tylko na świeżym
kodzie nie chroni starego — README „zaczyna kłamać” dokładnie tam, gdzie nikt już nie patrzy.

**EJ. Maskowanie nieczułe na wielkość liter zakryło ścieżkę zamiast sekretu.** Objaw: w raporcie
rozpoznania montaż `./docker/secrets:/run/secrets/cinema:ro` pokazał się jako
`./docker/secrets:***a:ro`. Przyczyna: wzorzec `SECRET\w*\s*[=:]` z modyfikatorem `i` potraktował
„secrets” z dwukropkiem jak przypisanie wartości. Nic nie wyciekło, ale raport stracił informację,
której szukaliśmy. Nauczka: filtr bezpieczeństwa testuje się na próbkach w OBIE strony — na
sekretach, które ma zakryć, i na zwykłych liniach, których ma nie ruszyć; druga wersja maskowania
wymaga nazwy zmiennej WIELKIMI literami i `=` albo `: ` ze spacją.

**EK. „Bez konfiguracji” nie znaczy „z regułami domyślnymi”, gdy narzędzie samo szuka pliku
konfiguracji.** Objaw (przy przygotowaniu testu dymnego bloku A, zanim paczka poszła do
uruchomienia): kontrola dodatnia „historia BEZ `--config` ma dać 3 znaleziska” dała 0.
Przyczyna: `gitleaks git <ścieżka>` bez `--config` szuka `.gitleaks.toml` W KATALOGU
SKANOWANYM, więc po dodaniu pliku do repozytorium każdy przebieg — także „kontrolny” — używa
wyjątków. Test przeszedłby u mnie przed blokiem i padł u Andrzeja po nim, albo odwrotnie:
dałby zielone światło skanerowi, który niczego nie widzi. Wyłapała to atrapa dockera
uruchamiająca prawdziwą binarkę gitleaks 8.30.1 na lustrze repozytorium. Nauczka: kontrolę
dodatnią ustawia się JAWNIE (osobny plik z samymi regułami domyślnymi), a nie przez brak
parametru — domyślne zachowanie narzędzia zależy od stanu katalogu, który właśnie zmieniamy.

---

## Blok A — wykonawca paczek i gitleaks (commit `22f9a5b`)

Raport: front 210/210 (`npm audit`: 0 podatności), Flutter 315/315, PHP 497, test dymny 23/0,
sprawdzacz README dla Etapu 9 — 0 braków, gitleaks na zmianach bloku — czysto. Etap 8 miał
przed blokiem 25 braków Vitest, po bloku 0.

**Obserwacja otwarta (do bloku D):** przed pierwszym przebiegiem bloku A wszystkie kontenery
z obrazem aplikacji (`php`, `worker`, `scheduler`, `reverb`) i `nginx` były zatrzymane z kodem
**127** („nie znaleziono polecenia”), wszystkie w tej samej chwili — przy starcie Dockera.
Postgres, Redis i Mailpit działały. W logach nic poza zwykłymi wpisami sprzed zatrzymania.
Przyczyny nie znamy; `docker compose down` i `up` przywróciły działanie. W bloku D, który
przebudowuje obraz i dodaje entrypoint, odtworzymy to celowo (restart Docker Desktop) i
sprawdzimy, co wstaje samo — zamiast zgadywać teraz.

---

## Blok A2 — sprawdzacz README na wszystkich sekcjach

### Decyzje

**370. Sprawdzacz bez argumentu sprawdza wszystkie sekcje „## Etap N” i żywą tabelę testów;
wykonawca i CI wołają go właśnie tak (`README_ETAPY="wszystkie"`).** Argument z nazwą sekcji
zostaje do diagnostyki. Minimum sprawdzonych nazw: 20 w każdej sekcji etapu i 100 łącznie.
Dotychczasowe 100 na wywołanie zatrzymywało poprawną sekcję Etapu 4 (49 nazw), a samo
minimum łączne przepuściłoby sekcję, z której przez błąd parsera nie wyszła ani jedna nazwa —
dlatego oba progi (strażnik pułapki AW na dwóch poziomach).

**371. Tabele testów w sekcjach etapów to stan na koniec etapu.** Sprawdzamy, że każda
wymieniona klasa i każdy plik nadal istnieją i że wiersze (z wierszem „Etapy 1–k”) sumują się
do „Razem”. Liczb nie porównujemy z dzisiejszym kodem. Odrzucone: podciągnięcie liczb do
dzisiejszych (tabela Etapu 3 przestałaby mówić, co dał Etap 3) oraz rezygnacja ze sprawdzania
tabel historycznych (klasa usunięta albo przemianowana zostałaby w README na zawsze).

**372. Żywa tabela: sumy na zestaw, liczba klas i plików oraz POKRYCIE, zamiast jednej tabeli
ze wszystkimi klasami.** Plan zakładał tabelę ok. 170 wierszy (71 klas PHP, 53 pliki Vitest,
48 plików Fluttera) — dublowałaby tabele etapów słowo w słowo. Ta sama gwarancja taniej: każda
zmiana liczby testów w dowolnej klasie zmienia sumę zestawu, a warunek pokrycia („każda klasa
i każdy plik testów występuje w README”) nie przepuści nowego testu bez słowa opisu. Pokrycie
od razu znalazło lukę: `ScreeningCancellationServiceTest` z Etapu 9 nie był w README nigdzie
(decyzja 376).

**373. `Backticki` znaczą nazwę z kodu i są sprawdzane; `<code>…</code>` znaczy przykład,
składnię SQL, polecenie powłoki albo nazwę celowo nieistniejącą i sprawdzany nie jest.**
Przeniesionych do `<code>`: 63 różne fragmenty z etapów 2–7 (np. `UPDATE … RETURNING`,
`docker compose exec`, `maxmemory`, „Bez `tickets_ready`”). Na GitHubie oba zapisy wyglądają
tak samo; różnica jest dla czytającego źródło i dla sprawdzacza. Odrzucone:
- rozpoznawanie SQL i poleceń po wyglądzie — heurystyka, która przepuści `SELECT`, przepuści
  też literówkę w nazwie kolumny obok,
- lista wyjątków w sprawdzaczu — odklejona od miejsca w README, rośnie bez kontroli.
Test dymny sprawdza obie strony: nieistniejąca nazwa w `<code>` jest pomijana, a TA SAMA nazwa
w backtickach — zgłaszana.

**374. Nazwy spoza repozytorium są sprawdzane, a nie przepuszczane.** Klasy wbudowane PHP
(`TypeError`, `\Throwable`) przez refleksję, klasy frameworka i bibliotek (`FormRequest`,
`RefreshDatabase`, `Pusher\ApiErrorException`) w `backend/vendor`: indeks samych nazw plików
(PSR-4: plik nazywa się jak klasa) i odczyt tylko pasujących plików, z kontrolą przestrzeni
nazw, gdy ją podano. Do tego: przypadki enumów (`UserRole::Customer` był zgłaszany, bo
sprawdzacz szukał tylko `function` i `const`), metody wołane przez `::` i `->` (`afterCommit`
w `DB::afterCommit`), a w korpusie `Dockerfile` (pakiety obrazu) i `composer.lock` (nazwy
pakietów, np. `guzzlehttp/psr7`).

**375. Ścieżki sprawdzamy wobec `git ls-files`, z rozwinięciem `{a,b}` jak w powłoce i z
domyślnym `.php`.** Pusta lista plików to STOP, nie „wszystko przeszło”. Pułapka EL.

**376. Prawdziwy dryf poprawiony w README (i w jednym miejscu w `.env.example`), nie w
sprawdzaczu.** Trasa planu sali z parametrem `{id}` zamiast `{screening}` i bez prefiksu;
`Cache::add()` w opisie bezpiecznika, choć kod woła `->add()` wstrzykniętego repozytorium
cache; `throw => false` zamiast `'throw' => false`; literówka „Dartcie”. Brakująca tabela
testów PHP Etapu 9 (cztery klasy, stan na koniec etapu). `MAIL_FROM_ADDRESS` i
`MAIL_FROM_NAME` w `.env.example` dostały wartości, które README Etapu 5 podaje jako domyślne.
Pozostały rozjazd `.env.example` z README i z działającym `.env` (`QUEUE_CONNECTION`,
`CACHE_STORE`, `DB_*`…) to blok B — sprawdzacz go nie widzi, bo porównuje nazwy, a wartości
łapie tylko wtedy, gdy są unikalnym napisem (`bilety@cinema.test` tak, `redis` nie).
`ExampleTest` (dwa przykłady ze szkieletu Laravela) opisany w żywej tabeli jako do usunięcia
w bloku B.

### Pułapki

**EL. Kontrola przez `file_exists` daje inny wynik na każdej maszynie.** Objaw: Etap 7 u
Andrzeja przechodził, a u mnie — na lustrze repozytorium z `git bundle` — zgłaszał
`BRAK ścieżka public/storage`. Przyczyna: `backend/public/storage` to dowiązanie tworzone
ręcznie według instrukcji uruchomienia, poza gitem; sprawdzacz pytał system plików, a nie
repozytorium. W CI (świeży klon) kontrola padłaby, a u autora przechodziła. Nauczka ta sama co
w EF, tylko dla narzędzia zamiast testu: kontrola, która ma dawać ten sam wynik u mnie i w
CI, może patrzeć wyłącznie na to, co jest w repozytorium — lokalne artefakty uruchomienia to
dane, których nie ma nikt poza mną.

**EM. Parser, który po cichu pomija nierozpoznany wiersz, zamienia brak dopasowania w brak
problemu.** Objaw: tabela Etapu 5 „sumowała się” według sprawdzacza do 123, a „Razem” mówiło
141 — wyglądało to na błąd README. Przyczyna: wzorzec wiersza wymagał `|` zaraz po nazwie
klasy, więc wiersze z dopiskiem, np. `RetryPolicyTest` (Unit), nie były ani sumowane, ani
sprawdzane — od Etapu 7, bez żadnego komunikatu. Nauczka: przy parsowaniu tabel suma kontrolna
(wiersze = „Razem”) jest tanią siatką na każdy pominięty wiersz — dlatego zostaje także w
tabelach historycznych. Ta sama siatka zadziałała przy pisaniu testu dymnego tego bloku:
mutacja zmieniła nazwę klasy tak, że przestała kończyć się na „Test”, wiersz wypadł z
parsowania — i wykryła to właśnie niezgodna suma.

**377. Testy „komponent przechodzi na trasę X” dostają trasy z pustymi widokami
(`frontend/src/__tests__/fixtures/router.ts`, funkcja `bezWidokow`).** Sprawdzają, DOKĄD
prowadzi nawigacja; ładowanie widoku docelowego nie jest ich przedmiotem, a przy prawdziwych
trasach było jedynym, co zależało od czasu. Ścieżki, nazwy, `meta`, `beforeEnter` i trasy
potomne zostają prawdziwe. Zmierzone w teście `CheckoutView.spec.ts`: nawigacja z prawdziwymi,
leniwymi widokami trwała 10–235 ms (pełny zestaw przy czterech procesach obciążających CPU),
z pustymi — 0–1 ms. Odrzucone: dłuższy limit `vi.waitFor` (przesuwa próg, a nie usuwa
zależności od czasu) i wstępne `import()` widoku w teście (ładowanie liczy się wtedy do limitu
całego testu — ten sam problem piętro wyżej). Zmienione trzy pliki, w których `vi.waitFor`
czeka na trasę: `CheckoutView.spec.ts` (dwa przypadki), `LoginView.spec.ts`,
`ScreeningSeatsCheckout.spec.ts`; test dymny pilnuje, żeby nowy test tego rodzaju też z tego
korzystał.

**EN. Test, który czeka na import() z domyślnym limitem 1 s, jest pomiarem obciążenia maszyny.**
Objaw: pierwszy przebieg bloku A2 zatrzymał się na froncie — `CheckoutView.spec.ts`,
„rezygnacja z płatności wraca do planu sali”: trasa wciąż `checkout` zamiast `screening-seats`.
Blok A2 nie dotyka frontu ani jedną linią; ten sam zestaw przeszedł 210/210 w bloku A
godzinę wcześniej. Przyczyna (szukana OBOK zmiany, według nauczki z Etapu 9): trasa ładuje
widok planu sali leniwie, a `vi.waitFor` domyślnie czeka 1000 ms. Pierwsze ładowanie dużego
widoku w danym wątku Vitesta zajmowało zmierzone 10–235 ms, a przebieg u Andrzeja był wyraźnie
wolniejszy niż w bloku A (36 s zamiast 31 s) — przy obciążonym dysku lub procesorze próg 1 s
został przekroczony. Nauczka: w teście oczekiwanie z limitem czasu jest w porządku tylko
wtedy, gdy czekamy na coś, co z definicji trwa krótko; gdy po drodze jest ładowanie kodu,
sieć albo dysk, test mierzy maszynę, a nie aplikację — i w CI, na współdzielonych
maszynach, taki test „czasem pada”, czyli uczy ignorować czerwony wynik.

Wynik bloku A2 (commit `b41a0fd`): sprawdzacz na wszystkich sekcjach — 1725 nazw, 0 braków;
test dymny 39/0, w tym dziewięć mutacji README, z których każda została wykryta. Pierwszy
przebieg zatrzymał się na czerwonym teście Vitest (pułapka EN), drugi — na braku aplikatora
łatek na pulpicie (plik przeniesiony do innego folderu; wykonawca ma ścieżkę pulpitu na
sztywno, zasada 5 z promptu Etapu 10).

---

## Blok B — porządki: `.env.example`, `APP_NAME`, `age_rating`, `ExampleTest`

### Decyzje

**378. `.env.example` opisuje działający stos z `docker-compose.yml`, a nie szablon Laravela.**
PostgreSQL (`postgres`, baza i użytkownik `cinema`), Redis dla cache, sesji i kolejki, Mailpit
jako SMTP, `REDIS_QUEUE_RETRY_AFTER=90`. Puste zostają tylko sekrety generowane lokalnie
(`APP_KEY`, `TICKET_QR_KEY`, klucze Reverba, Stripe'a i Firebase) — kroki `sed` w README
dalej trafiają w te same linie. Usunięte: blok zdublowanych ustawień domenowych, martwe
<code>SCREENING_CLEANUP_BUFFER</code> (kod czyta `SCREENING_CLEANUP_BUFFER_MINUTES`),
<code>VITE_APP_NAME</code>, klucze `AWS_*` i `MEMCACHED_HOST` (szablon Laravela; dysku S3 ani
memcached nie używamy). `DB_PASSWORD=secret` stoi jawnie, bo ta sama wartość jest jawnie
w `docker-compose.yml` i dotyczy tylko lokalnego kontenera; hasła produkcyjne — blok D.
Test dymny sprawdza nie tekst pliku, tylko to, że **wartości działają**: skrypt w kontenerze
`php` łączy się nimi z bazą, Redisem, Mailpitem i Reverbem, a te same połączenia z wartościami
sprzed bloku muszą się nie udać.

**379. `APP_NAME=Kino` zamiast trzech jawnych prefiksów.** Laravel wylicza z nazwy prefiksy
kluczy w Redisie (`kino-database-`, `kino-cache-`) i nazwę ciasteczka sesji (`kino-session`).
Odrzucone: `REDIS_PREFIX`, `CACHE_PREFIX` i `SESSION_COOKIE` ustawione osobno — trzy zmienne
do utrzymania w zgodzie zamiast jednej. Koszt zmiany w istniejącym środowisku: zadania
czekające w kolejce pod starym prefiksem nie wykonają się, a administratorzy zalogują się
ponownie — dlatego `.env` Andrzeja zmieniamy świadomie, a nie przy okazji. Wzorzec skanu
sekretów wykonawcy nie zależy już od nazwy aplikacji (pułapka EO).

**380. Fabryka filmów bierze kategorie wiekowe z `MovieAdminService::AGE_RATINGS`** — tej samej
listy, którą waliduje panel. Fabryka losowała amerykańskie `G`, `13+`, a seeder i panel używają
polskich (`B/O`, `12`, `15`…); dane testowe nie mogą mieć wartości, których aplikacja nie zna.
Jedyny test z `13+` (`TicketPdfRendererTest`) dostał `12`.

**381. `ExampleTest` usunięte (oba), a wykonawca umie usuwać pliki.** Pole `DELETE` w manifeście:
tylko plik śledzony przez gita, w dozwolonej ścieżce i o sumie znanej paczce; w `GIT_STATUS`
jako „ D”; `--cofnij` przywraca go z gita jak plik łatany. Generator paczek zamiast STOP przy
usuniętym pliku buduje wpis `DELETE`. PHPUnit: 495 testów w 69 klasach.

### Pułapki

**EO. Reguła skanera sekretów zależała od nazwy aplikacji.** Objaw (wychwycony przy
projektowaniu bloku, zanim zadziałał): po `APP_NAME=Kino` ciasteczko sesji panelu nazywa się
`kino-session`, a wzorzec skanu w wykonawcy szukał dosłownie „laravel[-_]session=” (pułapka AX
z Etapu 7). Zmiana jednej wartości konfiguracji wyłączyłaby po cichu jedną regułę
bezpieczeństwa. Nauczka: regułę wykrywania opiera się na KSZTAŁCIE (dowolna nazwa
`…-session=` z długą wartością), a nie na nazwie wyliczonej z konfiguracji — i sprawdza się ją
na próbkach: nowa nazwa wykryta, stara wykryta, sama nazwa w zdaniu bez wartości — nie.

**EP. „U mnie działa”, bo `.env` żyje własnym życiem.** Objaw: `.env.example` miał SQLite,
bazodanową kolejkę i cache, `MAIL_MAILER=log` i ani jednego hosta z `docker-compose.yml`,
a mimo to przez dziewięć etapów wszystko działało. Przyczyna: `.env` Andrzeja powstał z
przykładu w Etapie 0 i był poprawiany ręcznie; przykład nie. Instrukcja „Uruchomienie od
zera” kopiowała więc plik, z którym migracje nie przejdą (indeksy częściowe i `EXCLUDE` są
tylko w PostgreSQL). Nauczka: plik przykładowy jest kodem, więc musi mieć test — tu sondę
połączeń jego wartościami, a w CI (blok F) start środowiska wyłącznie z niego.

Wynik bloku B (commit `8cc9c7f`): test dymny 41/0 — w tym sonda połączeń wartościami z
`.env.example` (baza, Redis, poczta, Reverb: 4/4 OK; wartości sprzed bloku: 3 z 4 BŁĄD),
PHP 495, front 210, Flutter 315, README 0 braków.

---

## Blok B2 — sonda WebSocket jednym poleceniem

### Decyzje

**382. `bash tools/realtime-probe/run.sh` — sonda z Etapu 6 bez tinkera i bez ręcznych kroków.**
Dane przygotowuje `tools/realtime-probe/sonda.php` (seans w sprzedaży zaczynający się za więcej
niż godzinę, rezerwacja techniczna klientki `anna@cinema.test`, trzy tokeny Sanctum), a `run.sh`
blokuje i zwalnia miejsce przez API w chwilach, które sonda sama ogłasza („PROBE READY”,
„PROBE RECONNECTED”), po czym wysyła zdarzenie rezerwacji przez prawdziwy `RealtimeNotifier`.
Szczegóły, które mają znaczenie:
- skrypt PHP idzie do kontenera `php` na standardowe wejście, bo kontener widzi tylko `backend/`;
- tokeny trafiają z wyjścia `sonda.php prepare` prosto do pliku z prawami 600 w katalogu 700,
  do sondy — przez montowanie tylko do odczytu, do `curl` — przez plik nagłówków (`-H @plik`),
  a nie argument, który widać w liście procesów; na ekran nie trafiają nigdzie;
- rezerwacja techniczna jest od razu ANULOWANA: bez miejsc, bez płatności, poza zasięgiem
  sprzątania blokad; zdarzenie ma prawdziwy status tej rezerwacji;
- sprzątanie zawsze (`trap … EXIT`), a dane sondy rozpoznaje po stałej nazwie tokenów i powodzie
  anulowania, nie po identyfikatorach z pliku — działa także po przebiegu przerwanym w połowie.
Odrzucone: komenda artisana w aplikacji (narzędzie testowe w kodzie produkcyjnym) i
`tinker --execute` (cytowanie wielolinijkowego kodu w powłoce i brak czytelnego kodu wyjścia).

**383. Kontrola ujemna wbudowana w narzędzie: `PROBE_BEZ_KROKOW=1`.** Sonda bez blokady i bez
zdarzenia MUSI skończyć się FAIL i kodem 1 — test dymny sprawdza oba przebiegi. Zmierzone przed
wysłaniem paczki na prawdziwym Reverbie: przebieg pełny 15/15 PASS (kod 0), bez kroków 7/15
(kod 1), po obu zero tokenów i zero rezerwacji technicznych w bazie.

**384. Sprawdzacz README widzi też pliki nowe, jeszcze niedodane do gita** (`git ls-files
--cached --others --exclude-standard`). Wykonawca sprawdza README przed commitem, więc blok,
który dodaje plik i od razu opisuje go w README, dostawał BRAK. Pliki ignorowane nadal się nie
liczą (pułapka EL zostaje zamknięta).

**385. Środowisko do sprawdzania paczek po mojej stronie: PostgreSQL 16, Redis, PHP 8.4,
Node 22 i gitleaks 8.30.1 w kontenerze Claude'a, na lustrze repozytorium z `git bundle`.**
Pełny zestaw PHPUnit (495), Vitest, sprawdzacz README i sonda z prawdziwym Reverbem przechodzą
u mnie, zanim paczka trafi na pulpit. Nie zastępuje to przebiegu u Andrzeja — obraz Dockera,
Flutter i sieć Dockera są tylko tam — ale błędy logiki wychodzą teraz przed wysłaniem, a nie
po nim (pułapka EQ).

### Pułapki

**EQ. Funkcja globalna `event()` w skrypcie zasłoniła pomocnika Laravela.** Objaw: sonda na
lokalnym Reverbie — 12/15; blokady i zwolnienia miejsc dochodziły do klientów, ale zdarzenie
rezerwacji nie: w logu „Nie udało się rozgłosić zdarzenia”, wyjątek `TypeError`. To samo
zdarzenie wysłane ręcznie przechodziło. Przyczyna: `sonda.php` miał funkcję `event(int $id)`.
PHP deklaruje funkcje globalne przy KOMPILACJI skryptu — zanim wykona się `require` autoloadera
— a Laravel deklaruje swój `event()` tylko „jeśli jeszcze nie istnieje”. `RealtimeNotifier`
wołał więc funkcję sondy z obiektem zdarzenia. Nauczka: skrypt, który ładuje framework, nie
może mieć funkcji globalnych o nazwach pomocników frameworka (`event`, `app`, `config`,
`report`…) — prefiks albo klasa; i druga, szersza: narzędzie testowe, które po cichu robi co
innego niż kod produkcyjny, daje wynik o niczym. Wyszło przed wysłaniem paczki tylko dlatego,
że sonda miała po mojej stronie prawdziwy Reverb (decyzja 385).

Wynik bloku B2 (commit `6c717c3`): sonda 15/15 PASS przez nginx i Reverb u Andrzeja, kontrola
ujemna 7/15 z kodem 1, po obu zero danych sondy w bazie. Przed blokiem baza deweloperska nie
miała już ani jednego przyszłego seansu — seeder liczy repertuar od chwili uruchomienia
(2 dni wstecz, 13 naprzód), a baza była wypełniona dawno. Andrzej odświeżył ją
(`migrate:fresh --seed`). **Do bloku D i do przygotowania rozmowy:** dane demonstracyjne się
starzeją; przed prezentacją trzeba je odświeżyć, a entrypoint musi to uwzględnić.

---

## Blok C — nieudany zwrot (`refund.failed`) i „Stan prac”

### Decyzje

**386. Nieudany zwrot to znacznik rozliczenia, a nie nowy status rezerwacji.** Kolumny
`refund_failed_at` i `refund_failure_reason` (kod dostawcy, np. `expired_or_canceled_card`)
obok `refund_requested_at` i `refund_completed_at` z Etapu 7, z CHECK „nie ma porażki bez
zlecenia”. Status wraca z „zwrócona” na „anulowana” — miejsca i bilety są anulowane, a pieniądze
do klienta nie dotarły. Odrzucone: nowy status `refund_failed` — rozszedłby się po CHECK
w bazie, API, froncie Vue i aplikacji Fluttera, które dziś znają pięć statusów.

**387. Nieudanego zwrotu NIE ponawiamy automatycznie.** Rozliczenie zostaje zamknięte
(`refund_completed_at`), więc komenda `cinema:bookings:retry-refunds` go nie weźmie. Karta
zamknięta, zgubiona albo objęta sporem odrzuci każdą kolejną próbę, a każda próba to kolejne
zdarzenia i opłaty po stronie operatora. Panel pokazuje „zwrot NIEUDANY” z powodem po polsku
(`Labels::refundFailureReason`, nieznany kod — dosłownie), w logu idzie ostrzeżenie z
referencją rezerwacji i kodem przyczyny, bez danych osobowych. Zwrot inną drogą to decyzja
człowieka. Klient dostaje zmianę statusu na kanale rezerwacji, a feed sprzedaży — wpis.

**388. Webhook zna zdarzenia o obiekcie ZWROTU.** `WebhookEventData` dostał pole `refund`
(`RefundData`: identyfikator, płatność, czy nieudany, kod przyczyny) i metodę
`paymentIntentId()`, z której dziennik zdarzeń bierze płatność i rezerwację także dla
zdarzeń o zwrocie. Adapter Stripe'a przyjmuje `payment_intent` jako identyfikator albo
rozwinięty obiekt. Test przechodzi przez prawdziwy adapter i podpis (`RefundFailedWebhookTest`).
Poza kodem: endpoint webhooka w panelu Stripe'a musi mieć zaznaczone `refund.failed`.

**389. Wyścig „porażka przed zapisem przyjęcia” zamyka jeden warunek.** `refund.failed` potrafi
przyjść w milisekundach między odpowiedzią operatora na zlecenie zwrotu a transakcją, która
zapisuje jego przyjęcie. `failRefund()` ustawia wtedy `refund_completed_at`, a `completeRefund()`
już dziś kończy się na „rozliczenie zamknięte” — nie ustawi „zwrócona”. Pułapka ER.

**390. Etap 9 zaznaczony w „Stanie prac”** (dług z listy promptu Etapu 10).

### Pułapki

**ER. Warunek obronny, którego nie wymusza żaden test, tylko udaje zabezpieczenie.** Objaw:
pierwsza wersja `completeRefund()` dostała osobny warunek „jeśli zwrot oznaczono jako nieudany —
nic nie rób”. Test wyścigu przechodził. Mutacja — usunięcie tego warunku — test też przechodził.
Przyczyna: `failRefund()` zawsze zamyka rozliczenie, więc stary warunek „rozliczenie już
zamknięte” zatrzymywał wywołanie wcześniej; nowy był martwy. Warunek usunięty, a komentarz
przeniesiony tam, gdzie naprawdę zapada decyzja; mutacja TEJ linii (bez zamknięcia
rozliczenia) test wywraca. Nauczka: kod obronny sprawdza się mutacją — jeśli po usunięciu
warunku żaden test nie pada, to albo brakuje testu, albo warunek jest zbędny, a zbędny
warunek wprowadza czytelnika w błąd co do tego, gdzie jest zabezpieczenie.

Wynik bloku C (commit `8ab4357`): PHP 501 (w tym 6 nowych), test dymny 17/0 — podpisane
`refund.failed` przeszło przez nginx, podpis i adapter na żywym stosie (HTTP 200,
`unknown_booking`, płatność w dzienniku zdarzeń), migracja bazy deweloperskiej wykonana.

---

## Blok C2 — sprzątanie osieroconych plakatów

### Decyzje

**391. `cinema:posters:prune` usuwa plik tylko przy trzech warunkach naraz:** nazwa w kształcie,
który nadaje aplikacja (`posters/<ulid>.jpg`), brak filmu wskazującego plik i wiek ponad 24 godziny.
Okno 24 godzin, a nie sekundy: plik zapisany przed trwającą transakcją nie ma jeszcze wiersza,
a koszt pomyłki (utracony plakat) jest nieporównanie wyższy niż koszt sieroty leżącej do jutra.
`--older-than` nie schodzi poniżej godziny. Logika w `MovieAdminService::pruneOrphanPosters()`,
komenda jest cienka, jak pozostałe komendy harmonogramu; w harmonogramie codziennie o 3:45.

**392. Testy trzymają na dysku KOMPLET rodzajów plików naraz** (używany, stara sierota, świeża
sierota, obca nazwa), a każdy z trzech warunków i dolna granica okna przeszły test mutacyjny:
po usunięciu dowolnego z nich test pada. Samo „sierota znika” przepuściłoby komendę, która
kasuje cały katalog.

**393. Test dymny sprawdza bezpieczeństwo na PRAWDZIWYCH plakatach Andrzeja**, niezależną
miarą: najpierw `--dry-run` i porównanie jego listy z bazą (żaden wskazany plik na liście),
potem prawdziwy przebieg i sprawdzenie, że plik każdego filmu z plakatem nadal istnieje.
Do katalogu trafia na czas testu jedna podstawiona, postarzona sierota — i ona ma zniknąć.

Wynik bloku C2 (commit `1077bf2`): PHP 505 (w tym 4 nowe), test dymny 18/0. **Zastrzeżenie:**
baza Andrzeja nie miała ani jednego filmu z plakatem, więc u niego sprawdzenie „żaden plik
z bazy nie trafia na listę sierot” przeszło na pustym zbiorze. Tę własność pokryły przebieg
u mnie (film z plakatem) i mutacja usuwająca warunek „plik wskazany przez film” — test dymny
oblał ją dwiema niezależnymi kontrolami. Sam test dymny zostawił szkodę: pułapka ES niżej.

---

## Blok D — obraz wieloetapowy, entrypoint, sekrety tylko tam, gdzie potrzebne

### Rozpoznanie

Dwa skrypty u Andrzeja (`etap10_D_rozpoznanie.sh`, `etap10_D_restart.sh`), tylko odczyt,
poza naprawą katalogu plakatów:

- **baza `php:8.4-fpm-alpine` po digeście:** PHP 8.4.26, Alpine 3.24.2; w repozytorium Alpine są
  `su-exec`, `tini`, `pax-utils`, `zbar`, `imagemagick`. Obraz `cinema/php:dev` sprzed bloku:
  352 MB, entrypoint z bazy, `su-exec` brak, wszystkie pakiety `-dev` w środku.
- **biblioteki rozszerzeń** (scanelf): libpq, libzip, libpng, libjpeg-turbo, freetype, icu-libs,
  libgcc, libstdc++ — plus te, które baza już ma (musl, zlib, libsodium).
- **uprawnienia:** `storage/` i `bootstrap/cache` należą do użytkownika WSL z prawami 777,
  pliki tworzone przez kontenery do 82:82; **`storage/app/public/posters` należał do roota (755)**
  — pułapka ES; **plik konta Firebase 600, właściciel 1000** — worker (uid 82) go nie czyta.
- **kod 127:** pierwsze podejście („Quit Docker Desktop”) nie zrestartowało silnika — kontenery
  miały ten sam `StartedAt` co przed nim. Dopiero `wsl --shutdown` odtworzył zdarzenie:
  pułapka ET.

### Decyzje

**394. Jeden `docker/php/Dockerfile`, cztery etapy:** `base` (rozszerzenia, biblioteki
uruchomieniowe, `su-exec`, entrypoint, `uploads.ini`), `dev` (composer, git, unzip, zbar,
imagemagick; kod z bind mountu), `vendor` (etap budowania: `composer install --no-dev`,
autoloader klasowy, `package:discover` bez pakietów deweloperskich) i `prod` (kod i vendor
w obrazie, `php.ini-production`, OPcache bez sprawdzania dat plików). Compose buduje `dev`.
Odrzucone: osobne Dockerfile dla dev i prod — rozjechałyby się przy pierwszej zmianie
rozszerzeń; obraz produkcyjny z composerem i narzędziami testowymi — większa powierzchnia
ataku i rozmiar bez żadnej korzyści w działaniu.

**395. Biblioteki uruchomieniowe wylicza `scanelf` z gotowych plików `.so`**, a pakiety `-dev`
i `$PHPIZE_DEPS` znikają w TEJ SAMEJ warstwie, w której powstały. Ręczna lista bibliotek
rozjechałaby się z wersją Alpine (biblioteki ICU mają w nazwie numer wersji: `libicuuc.so.78`).
Rozszerzenie redis z PECL przypięte (`redis-6.3.0`, najnowsze stabilne na pecl.php.net) —
wcześniej `pecl install redis` brał to, co najnowsze w chwili budowy.

**396. Wszystkie obrazy po digeście:** baza PHP, źródło composera (`composer:2@sha256:…`),
nginx, postgres, redis i mailpit (`latest@sha256:…` — digest zamraża wersję z dnia rozpoznania).
Digest to indeks wieloplatformowy, więc ten sam zapis działa na amd64 i arm64.

**397. Entrypoint w powłoce robi sprawy systemowe, a decyzje o danych podejmuje komenda
`cinema:boot` z testami.** Powłoka: użytkownicy (`su-exec`), `.env` i klucze w dev, composer,
katalogi, dowiązanie, znacznik gotowości. PHP: migracje z `--isolated`, dane demonstracyjne
tylko do pustej bazy i nigdy w produkcji, ostrzeżenie o zestarzałym repertuarze, kontrola
pliku push. Każda z tych reguł przeszła test mutacyjny (6 mutacji, 6 wykrytych — w tym ta,
która w ogóle nie wołała migracji: pierwsza wersja testu jej nie widziała, bo baza testowa
jest już zmigrowana; teraz test wymaga komunikatu samej komendy `migrate`).

**398. Przygotowanie tylko w kontenerze `php` (`CINEMA_SETUP=1`), a worker, scheduler i Reverb
czekają na jego healthcheck** (znacznik `/tmp/cinema-ready`, `start_period` 300 s na pierwsze
`composer install`). Odrzucone: migracje w każdym kontenerze — cztery równoległe migracje;
osobna jednorazowa usługa `migrate` z `service_completed_successfully` — w dev php i tak musi
poczekać na `composer install` i `.env`, więc przygotowanie i tak siedziałoby w jednym miejscu.
`--isolated` chroni przed dwiema replikami w produkcji.

**399. Entrypoint tylko UZUPEŁNIA:** tworzy `.env`, gdy go nie ma, i wpisuje klucze, które
w nim są puste. Ustawionej wartości nie zmienia nigdy. Zestarzałych danych demonstracyjnych
nie odświeża sam — `migrate:fresh` kasuje także dane wpisane ręcznie, więc to decyzja
człowieka; entrypoint tylko ostrzega (poza produkcją). W produkcji: bez seedowania (konta
demonstracyjne mają jawne hasła) i STOP bez `APP_KEY` w środowisku — wygenerowanie klucza
przy starcie unieważniłoby sesje i zaszyfrowane dane przy każdym restarcie.

**400. `docker/secrets/` tylko w workerze**, bo powiadomienia push wysyła wyłącznie kolejka
(`ShouldQueue` we wszystkich powiadomieniach). Uprawnienia w dev: grupa 82 i `chmod 640`
(wcześniej README kazało 644 — czytelny dla każdego użytkownika WSL). Worker przy starcie
sprawdza plik (`cinema:boot --check-push`) i ostrzega zamiast przerywać: maile i PDF-y działają
bez push. Odrzucone: `secrets:` w Compose — sekret z pliku to i tak montaż pojedynczego pliku,
czyli dokładnie to miejsce, które po restarcie WSL kończy start kodem 127 (ET).

**401. `uploads.ini` w obrazie zamiast montażu pliku.** Konfiguracja PHP należy do obrazu, a montaż
pojedynczego pliku z WSL to jedno z dwóch miejsc porażki z ET. Drugie — `docker/nginx/default.conf`
— zostaje do bloku E, który i tak przebudowuje nginx (TLS).

**402. Obraz produkcyjny:** kod należy do roota i jest tylko do odczytu dla procesów aplikacji,
zapis tylko do `storage/` (w produkcji wolumen) i `bootstrap/cache`; `clear_env = no` w puli FPM,
bo konfiguracja przychodzi ze środowiska, nie z `.env`; każdy kontener przy starcie robi
`artisan optimize` ze swoich zmiennych (każdy ma własny system plików obrazu). Sprawdzone u mnie
bez `.env` i bez zmiennych: `package:discover` i `optimize` przechodzą (75 tras w cache).
`expose_php = Off` ustawione jawnie (`docker/php/prod/security.ini`): `php.ini-production` z php-src
zostawia `On`, a wyłączenie robią dopiero łatki dystrybucji (pułapka EV).

### Pułapki

**ES. Test dymny uruchomiony jako root zostawił katalog, do którego aplikacja nie zapisze.**
Objaw: rozpoznanie bloku D pokazało `storage/app/public/posters` z właścicielem root i prawami
755 — panel nie zapisałby żadnego plakatu (PHP-FPM działa jako www-data). Przyczyna: test dymny
C2 podkładał sierotę przez `Storage::put()` poleceniem `docker compose exec php …`, czyli jako
root, a katalogu nie było, bo żaden film nie miał plakatu — Laravel założył go z właścicielem
procesu. Naprawa: `chown 82:82` (skrypt restartu), a entrypoint od teraz dodaje prawo zapisu
katalogom w `storage/`, które go nie mają. Nauczka: wszystko, co tworzy pliki w danych
aplikacji — także testy dymne i skrypty pomocnicze — wykonuj jako użytkownik aplikacji
(`exec -u 82:82`, `su-exec`); „przeszło” nie znaczy „niczego nie zepsuło”, więc rozpoznanie
po bloku sprawdza też właścicieli katalogów zapisu.

**ET. Kod 127 po restarcie WSL to nieudany montaż, a nie brak polecenia.** Objaw: po
`wsl --shutdown` (i wcześniej po restarcie Windows) `php`, `worker`, `scheduler`, `reverb`
i `nginx` stały z kodem 127, a postgres, redis i mailpit działały. `docker inspect` →
`State.Error`: `error mounting "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/…"
to rootfs at "/usr/local/etc/php/conf.d/zz-uploads.ini" … not a directory` (nginx: to samo dla
`default.conf`). Docker Desktop pokazał okno „WSL integration with distro 'Ubuntu' unexpectedly
stopped”: jego proces pomocniczy próbował się skopiować do `/run/docker-desktop`, zanim Ubuntu
skończyło start (lakoniczne `install: No such file or directory` z uutils). Przyczyna: Docker
startuje kontenery z polityką restartu, zanim podłączy dystrybucję; montaż pojedynczego PLIKU
z dystrybucji zawodzi, nieudany start dostaje kod 127, a polityka `unless-stopped` nie ponawia
startu, który nigdy się nie udał. Kontenery bez montaży plików wstały same. Naprawa: „Restart
the WSL integration” i `docker compose up -d`; mniej montaży plików (`uploads.ini` w obrazie,
sekrety tylko w workerze), a obraz produkcyjny nie ma ich wcale. Nauczka: kod wyjścia
kontenera, który NIGDY nie wystartował, nie pochodzi od programu — przyczyna jest w
`State.Error`, a `docker compose down` kasuje ten dowód; najpierw `docker inspect`, potem
naprawa. I: odtwarzając zdarzenie, sprawdź, że naprawdę zaszło (tu: niezmieniony `StartedAt`
zdradził, że „Quit” nie zatrzymał silnika).

**EU. Pomiar kolejności zepsuł krok, który przyszedł po akcji, a przed pomiarem.** Objaw:
pierwszy przebieg testu dymnego bloku D: scheduler „wystartował przed gotowością php”, worker
i reverb po niej. Raport wykonawcy pokazał, że Compose zrobił to dobrze (`cinema_php Healthy`,
dopiero potem `Starting` trzech zależnych). Przyczyna: następny krok wykonawcy,
`docker compose up -d --force-recreate nginx`, odtworzył także `php` — zależność nginx — więc
entrypoint wykonał przygotowanie drugi raz, a jego `queue:restart` i `reverb:restart` zrestartowały
worker i Reverb (scheduler na te sygnały nie reaguje). Test porównał czasy z DRUGĄ gotowością.
Nauczka: pomiar zależności czasowych rób w sekwencji, którą test wykonuje sam, tuż przed
pomiarem — między akcją a sprawdzeniem w potoku mogą działać inne kroki. Przy okazji wyszło,
że sygnały restartu działają: po ponownym przygotowaniu worker i Reverb wstały z nowym kodem.

**EV. Wzorce sprawdzone na próbce napisanej z pamięci, a nie z wyjścia narzędzia.** Objaw: trzy
fałszywe FAIL w pierwszym przebiegu testu dymnego D. `php --ri redis` wypisuje
„Redis Version => 6.3.0”, a wzorzec szukał wiersza zaczynającego się od „Version”; `expose_php`
w `php.ini-production` z php-src to `On` (Debian, z którego znałem plik, łata go na `Off`);
`unzip` w Alpine to aplet busyboxa obecny w każdym obrazie, więc „brak polecenia unzip” nie mógł
odróżnić obrazu prod od dev. Przyczyna: zasada „każdy wzorzec najpierw na pliku, który ma go
spełniać” była wykonana na próbce, którą sam napisałem według wyobrażenia o formacie. Nauczka:
próbka do sprawdzenia wzorca pochodzi z prawdziwego narzędzia (u mnie: lokalne PHP z redis 6.3.0);
gdy narzędzia nie ma, pytaj o wartości o zdefiniowanym formacie (`phpversion("redis")`,
`ini_get()`, `apk info -e`) zamiast parsować tekst przeznaczony dla ludzi — a obecność
pakietu sprawdzaj w menedżerze pakietów, nie przez `command -v`.

**EW. Entrypoint zmienił katalog roboczy narzędziom, które używają obrazu inaczej niż Compose.**
Objaw: drugi przebieg bloku D — test dymny 55/0, PHPUnit 509 — zatrzymał się na sprawdzaczu
README: `Could not open input file: tools/readme-compliance/check.php`. Przyczyna: wykonawca
uruchamia sprawdzacz (a także lint i aplikator łatki) przez `docker run -v "$PWD":/work -w /work
cinema/php:dev …`, a entrypoint na początku robił `cd /var/www/html` i w tym katalogu wykonywał
polecenie końcowe — `-w` przestało działać. Wyszło dopiero w kroku 12b, bo lint i łatka biegną
jeszcze na starym obrazie, przed przebudową. Naprawa: entrypoint zapamiętuje katalog
wywołania i wraca do niego przed `exec`; test dymny sprawdza `docker run -w /work … pwd`.
Nauczka: entrypoint owija KAŻDE użycie obrazu — Compose, CI, `docker run` z narzędzi — więc
poza swoimi krokami nie może zmieniać kontekstu wywołującego (katalog, argumenty, użytkownik);
obraz testuj także w roli narzędzia, a nie tylko usługi.

Wynik bloku D (commit `6a4ad04`, trzeci przebieg): test dymny 57/0, PHP 509 (w tym 4 nowe),
sprawdzacz README 1740/0. Obraz prod 61 MB wobec 75 MB dev (rozmiar wg `docker image inspect`).
Pierwszy przebieg: 6 FAIL — 1 prawdziwy (plik Firebase nieczytelny dla workera; Andrzej:
`chgrp 82`, `chmod 640`) i 5 fałszywych (EU, EV); drugi: entrypoint psuł `docker run -w` (EW).

---

## Blok E — HTTPS na nginx, zaufane proxy, Web Push poza localhost

### Rozpoznanie

- nginx 1.27.5 (przypięty): `http_ssl_module`, `http_v2_module`, `http_v3_module`; biblioteka
  libssl 3, ale **bez polecenia `openssl`**; Alpine 3.21.3.
- `cinema/php:dev` ma `openssl` (OpenSSL 3.5.8). Port 8443 na Windows wolny. `mkcert` nie jest
  zainstalowany, `winget` jest. Adres komputera w Wi-Fi: 192.168.1.152.
- W kodzie: klienci (Vue, panel) wybierają `wss://`/`ws://` z adresu strony i mają na to testy;
  `fcm_options.link` jest od Etapu 8 i czeka tylko na `APP_URL` z HTTPS; `bootstrap/app.php` nie
  konfiguruje zaufanych proxy, a wbudowany `TrustProxies` czyta `config('trustedproxy.proxies')`
  przy każdym żądaniu — wystarczy plik konfiguracji, bez własnego middleware.

### Decyzje

**403. TLS kończy nginx; dwa serwery: :80 zostaje, :443 dochodzi** (<https://localhost:8443>,
TLS 1.2/1.3, HTTP/2). `http://localhost:8080` zostaje, bo korzysta z niego aplikacja mobilna
(`adb reverse`), sonda WebSocket i skrypty. **Bez przekierowania i bez HSTS w dev:** HSTS dotyczy
całego hosta bez portu, więc przeglądarka zamieniłaby też `http://localhost:8080` na HTTPS —
zepsułoby to i ten projekt, i każdy inny na localhost. Przekierowanie i HSTS — tylko produkcja.
Wspólna treść obu serwerów w `docker/nginx/cinema.conf` (include), a nie w dwóch kopiach.

**404. Certyfikat: własny z `docker/nginx/certs/` albo samopodpisany do wolumenu** (`tls.sh`
w `/docker-entrypoint.d/` obrazu nginx). Własny (mkcert) ma pierwszeństwo przy każdym starcie;
samopodpisany powstaje raz i przetrwa odtworzenie kontenera (wolumen `nginxtls`); znacznik
pochodzenia sprawia, że po usunięciu własnego wraca samopodpisany zamiast starej kopii. Nic nie
trafia do katalogu repozytorium, klucz ma prawa 600. Odrzucone: certyfikat w repozytorium
(zakaz z promptu), generowanie w kontenerze php do bind mountu (pliki roota w repozytorium —
pułapka ES), Let's Encrypt (wymaga publicznej domeny — to sprawa wdrożenia produkcyjnego).
Wszystkie ścieżki skryptu sprawdzone lokalnie: pierwszy start, drugi start, własny, usunięty.

**405. Obraz `cinema/nginx:dev` z konfiguracją w środku** (`docker/nginx/Dockerfile`, baza po
digeście, `openssl` tylko dla `tls.sh`). Po blokach D i E stos nie montuje już żadnego
pojedynczego pliku — znikają oba miejsca porażki z pułapki ET i pułapka BI.

**406. Zaufane proxy: `config/trustedproxy.php` z `TRUSTED_PROXIES`, domyślnie nikomu.** Za nginx
nie są potrzebne: FastCGI przekazuje `HTTPS=on`. Lista jest dla zewnętrznego load balancera albo
CDN przed nginx. Do tego nginx jako brzeg **nadpisuje** `X-Forwarded-For`/`-Proto` własną
wiedzą, zeruje `X-Forwarded-Host`/`-Port` i usuwa nagłówek `Proxy` (httpoxy) — gdyby ktoś dopisał
do listy sieć Dockera, klient nadal nie poda sobie cudzego IP (limity logowania i blokad miejsc
są liczone po IP) ani HTTPS. Sprawdzone na żywo lokalnie: nginx z tą konfiguracją przed
minimalnym serwerem FastCGI, który odsyła otrzymane parametry — `X-Forwarded-For: 6.6.6.6`
od klienta dotarł jako adres połączenia, `Proxy` i `X-Forwarded-Host` nie dotarły wcale.
Odrzucone: `TRUSTED_PROXIES=*` domyślnie (każdy klient ustawia sobie IP), własny middleware
(wbudowany robi to samo z konfiguracji).

**407. `SESSION_SECURE_COOKIE` puste w dev, `true` w produkcji; `APP_URL` w `.env.example` zostaje
na HTTP.** Panel działa w dev pod oboma adresami. Przełączenie linków z maili i push na HTTPS to
świadoma zmiana jednej zmiennej — ma sens z certyfikatem, któremu ufa przeglądarka.

**408. Web Push poza localhost wymaga ZAUFANEGO certyfikatu — mkcert.** Przy samopodpisanym
przeglądarka odmawia rejestracji service workera, więc „HTTPS” sam nie wystarcza. README ma
instrukcję (Windows: `winget`, `mkcert -install`, certyfikat z adresem komputera w sieci).
CA i jego klucz zostają w profilu Windows, nigdy w repozytorium. Test na żywo (subskrypcja pod
adresem z sieci, kliknięcie w powiadomienie z `fcm_options.link`) — osobna lista kontrolna,
na danych testowych Andrzeja.

**409. Klienci bez zmian.** Schemat WebSocketu wynika z adresu strony (testy Vitest z Etapu 8:
`http:` → `ws` 8080, `https:` → `wss` 443); po stronie serwera publikacja do Reverba zostaje
po HTTP wewnątrz sieci Dockera.

### Pułapki

**EX. Kontrola ujemna zawiodła po stronie KLIENTA, a wyglądała na odpowiedź serwera.** Objaw:
lokalne sprawdzenie „TLS 1.1 odrzucony” dało błąd — ale `no protocols available`
z `tls_setup_handshake`, czyli OpenSSL 3 klienta nawet nie wysłał powitania (TLS 1.1 wyłączony
domyślnie poziomem bezpieczeństwa). Serwer nie był w ogóle pytany. Dopiero klient z
`-cipher DEFAULT@SECLEVEL=0` dostał od serwera `alert protocol version` (alert 70), a TLS 1.2
z tego samego klienta przeszedł. Nauczka: test „X jest odrzucane” jest dowodem tylko wtedy,
gdy odrzucenie przychodzi od sprawdzanej strony — sprawdź, KTO zgłosił błąd, i dołóż kontrolę
dodatnią tym samym narzędziem (tu: TLS 1.2 przyjęty).

**EY. Test, który ustawia `config()` sam, omija drogę ze zmiennej środowiskowej.** Objaw: mutacja
„plik konfiguracji zawsze zwraca null” przeszła przez trzy testy zaufanych proxy — wszystkie
ustawiały `config(['trustedproxy.proxies' => …])` bezpośrednio, więc nie sprawdzały, czy
`TRUSTED_PROXIES` w ogóle do konfiguracji dociera. Dopisany test czyta plik konfiguracji
z ustawioną zmienną (wartość i pusty napis). Nauczka: przy funkcji sterowanej zmienną
środowiskową jeden test musi przejść całą drogę env → config → zachowanie; testy z gotową
konfiguracją sprawdzają tylko drugą połowę.

**EZ. Sprawdzenie stanu drzewa gita w teście, który biegnie przed commitem bloku.** Objaw:
pierwszy przebieg bloku E — 36 OK i jeden FAIL „nowe pliki w docker/nginx/certs”. Przyczyna:
`git status` pokazał jako nowy `docker/nginx/certs/.gitignore` z tej samej paczki — wykonawca
commituje dopiero po teście dymnym, więc każdy nowy plik bloku jest w tej chwili „nieśledzony”.
Zamiast stanu sprawdzamy właściwość: `git check-ignore` musi ignorować `cert.pem` i `key.pem`,
a nie może ignorować samego `.gitignore` (kontrola dodatnia). Nauczka: test, który biegnie
w środku procesu, sprawdza własności, a nie migawkę stanu — chyba że stan „przed commitem”
jest dokładnie tym, co chcemy zmierzyć.
