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
