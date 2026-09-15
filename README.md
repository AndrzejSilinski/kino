# System rezerwacji biletów kinowych

Pełna ścieżka sprzedaży biletów dla sieci kin: wybór kina i seansu, interaktywny
plan sali z atomową blokadą miejsc w czasie rzeczywistym, płatność Stripe,
bilety z kodem QR w PDF, panel administracyjny oraz aplikacja mobilna.

## Stack

| Warstwa | Technologia | Uzasadnienie |
|---|---|---|
| Backend | Laravel 13, PHP 8.4 | wymóg zadania |
| Baza danych | PostgreSQL 16 | patrz niżej |
| Cache i sesje | Redis 7 | |
| Serwer WWW | nginx + PHP-FPM (Alpine) | |
| Konteneryzacja | Docker Compose | |

### Dlaczego PostgreSQL, a nie MySQL

Kluczowe mechanizmy bezpieczeństwa danych w tym systemie opierają się na
funkcjach, których MySQL nie posiada:

1. **Częściowe indeksy unikalne** (`UNIQUE ... WHERE ...`) — pozwalają wymusić
   „jedna aktywna blokada na miejsce" i „jeden nieanulowany bilet na miejsce"
   na poziomie bazy, bez blokad aplikacyjnych.
2. **Constraint `EXCLUDE USING gist`** — uniemożliwia zapisanie dwóch seansów
   nakładających się czasowo w tej samej sali.

Obie te rzeczy dałoby się zasymulować w MySQL kolumnami pomocniczymi i
transakcjami, ale kosztem złożoności i z gorszą gwarancją. Konsekwencja wyboru:
projekt jest związany z PostgreSQL — testy nie uruchomią się na SQLite,
co i tak byłoby bez sensu przy testowaniu współbieżności.

## Uruchomienie od zera

```bash
git clone <repo> cinema
cd cinema
cp backend/.env.example backend/.env
docker compose up --build -d
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
```

Aplikacja: <http://localhost:8080>

Konta testowe (hasło `password`):

| E-mail | Rola |
|---|---|
| admin@cinema.test | administrator |
| anna@cinema.test | klient |
| piotr@cinema.test | klient |
| maria@cinema.test | klient |

## Struktura repozytorium
cat >> ~/cinema/README.md <<'MD'

## Model danych
cat >> ~/cinema/README.md <<'MD'

## Dane testowe

Seeder tworzy 3 kina w różnych miastach, 7 sal w trzech układach (z przejściami,
strefą Premium, rzędem VIP w sali IMAX, kanapami dla par w ostatnim rzędzie
i miejscami dla osób z niepełnosprawnością przy wejściu), 8 filmów oraz
repertuar od 2 dni wstecz do 13 dni w przód wraz z cennikami.

Ceny są wyliczane z ceny bazowej kategorii przez mnożniki: weekend +20%,
seans wieczorny +15%, poranek −20%, 3D ×1,15, IMAX ×1,35.

Generator repertuaru przesuwa kursor czasu dopiero za `slot_ends_at`
poprzedniego seansu, dzięki czemu nigdy nie narusza constraintu
`screenings_no_overlap`. Gdyby algorytm się pomylił, seeder przerwałby się
błędem bazy — poprawność jest weryfikowana przez samą bazę, nie przez
zaufanie do kodu.

## Znane ograniczenia i co zrobiłbym mając więcej czasu

- **Brak zakupu jako gość.** Wymagałby dostępu do biletów przez podpisany URL
  z ograniczonym czasem życia.
- **Gatunki filmów jako tablica `jsonb`.** Wystarczające do filtrowania;
  osobna tabela słownikowa byłaby potrzebna dopiero przy stronach gatunków
  i statystykach.
- **Kategorie cenowe globalne dla sieci.** Rozszerzenie do kategorii per kino
  to jedna nullowalna kolumna.
- **`halls.grid_rows` / `grid_cols` zdenormalizowane** względem `seats`.
  Świadome — frontend potrzebuje wymiarów planu przed pobraniem miejsc.
  Utrzymanie spójności należy do edytora układu sali.
- **Projekt związany z PostgreSQL.** Konsekwencja użycia indeksów częściowych
  i `EXCLUDE`; przenośność na inny silnik nie była celem.

## Stan prac

- [x] Etap 0 — Docker Compose, szkielet Laravela
- [x] Etap 1 — model danych, migracje, modele Eloquent, seeder
- [ ] Etap 2 — blokowanie miejsc i test współbieżności
- [ ] Etap 3 — REST API ścieżki zakupowej
- [ ] Etap 4 — Stripe, webhook, obsługa wyścigu przy płatności
- [ ] Etap 5 — bilety, QR, PDF, kolejki, mail
- [ ] Etap 6 — WebSocket (Laravel Reverb)
- [ ] Etap 7 — panel administracyjny (Livewire)
- [ ] Etap 8 — frontend Vue 3
- [ ] Etap 9 — aplikacja Flutter
- [ ] Etap 10 — CI/CD i dokumentacja

---

## Etap 2 — Blokowanie miejsc (współbieżność)

Najważniejsza część zadania. Gdy przy premierze setki osób klikają ten sam fotel,
system musi zagwarantować, że dokładnie jedna z nich go dostanie — i musi to
zagwarantować **baza danych**, a nie warunek w PHP.

### Co powstało

| Plik | Rola |
|---|---|
| `app/Services/SeatLockService.php` | cała logika: zakładanie, zwalnianie, czyszczenie blokad |
| `app/Exceptions/CinemaException.php` | bazowy wyjątek domenowy (status HTTP + kod błędu + kontekst) |
| `app/Exceptions/SeatsUnavailableException.php` | konflikt miejsca — 409 |
| `app/Exceptions/InvalidSeatSelectionException.php` | błędne dane wejściowe — 422 |
| `app/Exceptions/SeatLockLimitExceededException.php` | przekroczony limit miejsc — 422 |
| `app/Exceptions/ScreeningNotBookableException.php` | seans odwołany / zakończony / rozpoczęty — 409 |
| `app/Console/Commands/SweepExpiredSeatLocks.php` | czyszczenie wygasłych blokad (scheduler) |
| `app/Console/Commands/AttemptSeatLock.php` | pojedyncza próba blokady — narzędzie testu współbieżności |
| `tests/Feature/SeatLockConcurrencyTest.php` | **test obowiązkowy**: 20 równoczesnych procesów |
| `tests/Feature/SeatLock{Service,Expiry,Validation}Test.php` | 22 testy jednostkowe |
| `database/factories/*.php` | 10 factories |

### Mechanizm: częściowy indeks UNIQUE w PostgreSQL

Atomowość zapewnia jeden obiekt w bazie:

```sql
CREATE UNIQUE INDEX seat_locks_active_unique
    ON seat_locks (screening_id, seat_id)
    WHERE released_at IS NULL;
```

Gdy dwie transakcje próbują wstawić wiersz o tym samym kluczu, PostgreSQL wykrywa
konflikt na poziomie strony B-drzewa. Druga transakcja **blokuje się** i czeka na
rozstrzygnięcie pierwszej: po jej `COMMIT` dostaje `SQLSTATE 23505`, po `ROLLBACK`
spokojnie wstawia własny wiersz. Nie istnieje okno czasowe, w którym obie mogłyby
przejść — w odróżnieniu od naiwnego `if (! exists) insert`, które jest klasycznym
TOCTOU i przy stu równoczesnych żądaniach po prostu nie działa.

Wymuszenie leży w bazie, nie w aplikacji: nawet zapis wykonany z konsoli psql,
z pominięciem serwisu, nie złamie tej zasady.

### Dlaczego nie `SELECT ... FOR UPDATE`

`FOR UPDATE` blokuje **istniejące wiersze**. Nie blokuje ich nieobecności —
zapytanie zwracające zero wierszy nie zakłada żadnej blokady, więc dwa procesy
dostają pustkę i oba wstawiają. To phantom read i dokładnie ten błąd, którego
szuka się w tym zadaniu.

Żeby `FOR UPDATE` miało co blokować, potrzebna byłaby prefabrykowana tabela
`screening_seats` ze stanem każdego fotela na każdym seansie — miliony wierszy
generowanych z góry dla seansów, na które nikt nie przyjdzie. Wariant alternatywny,
blokowanie wiersza `screenings` jako mutexu na cały seans, serializuje całą salę:
przy premierze 300 osób wybierających **różne** miejsca stoi w jednej kolejce.

Poprawne warianty (`SERIALIZABLE` z pętlą retry na `40001`, `pg_advisory_xact_lock`)
działają, ale są droższe i mniej czytelne, a ograniczenie unikalności i tak zostałoby
jako pas bezpieczeństwa. Dwa mechanizmy zamiast jednego to koszt bez zysku.

### Dlaczego nie Redis `SET NX PX`

Redis kusi atomowym `SETNX` i wbudowanym TTL, ale wprowadza **drugie źródło prawdy**.
Bilety są w PostgreSQL; przy finalizacji płatności trzeba atomowo sprawdzić blokadę
i wystawić bilet, czego nie da się objąć jedną transakcją obejmującą Redis i Postgres
bez two-phase commit albo wzorca outbox — nakład nieproporcjonalny do zysku.
Dochodzi trwałość (`appendfsync everysec` gubi do sekundy zapisów, failover na replikę
gubi blokady) oraz brak śladu audytowego i raportowania.

Redis jest w projekcie i zostanie użyty do cache repertuaru i kolejek, ale
**nie odpowiada za poprawność blokad**. Przy skali wymagającej tysięcy blokad na
sekundę byłby szybkim filtrem przed bazą — warstwą dodatkową, nie zamiennikiem.

### Pułapka: predykat indeksu nie może zawierać `now()`

Naturalnym odruchem jest napisanie predykatu jako
`WHERE released_at IS NULL AND expires_at > now()`. PostgreSQL na to nie pozwoli —
w predykacie indeksu wolno używać wyłącznie funkcji `IMMUTABLE`, a `now()` jest
`STABLE`. Konsekwencja jest fundamentalna dla całego projektu:

> **Blokada, która wygasła, ale nie została zwolniona, nadal zajmuje wpis w indeksie.**

Gdyby serwis tego nie obsłużył, miejsce po porzuconym koszyku byłoby zablokowane
na zawsze. Dlatego `SeatLockService::lock()` zwalnia wygasłe blokady
**w tej samej transakcji, tuż przed własnym INSERT-em** — między jednym a drugim
nie ma okna, w które ktoś mógłby wejść.

Scheduler czyszczący wygasłe blokady to **higiena, nie mechanizm poprawności**.
Gdyby nie działał wcale, system nadal sprzedawałby prawidłowo; rosłaby tylko tabela,
a plan sali pokazywałby zajęte miejsce do chwili, gdy ktoś w nie kliknie.

### Algorytm `lock()`

Poza transakcją (tanio, bez trzymania locków):

1. Seans jest `bookable` i jeszcze się nie zaczął — inaczej **409**.
2. Lista miejsc niepusta, bez duplikatów — inaczej **422**.
3. Wszystkie miejsca należą do sali tego seansu i są aktywne — inaczej **422**.
   Ta kontrola jest konieczna, bo `seat_locks` ma klucz obcy do `seats`, a nie do
   `halls`: bez niej dałoby się zablokować fotel z innego kina.

W transakcji:

4. **Sortowanie `seat_id` rosnąco.** Gdy jeden klient bierze miejsca `{5, 9}`,
   a drugi `{9, 5}` i każdy idzie w swojej kolejności, powstaje cykl oczekiwań —
   deadlock, który PostgreSQL rozstrzyga zabiciem jednej transakcji (`40P01`),
   czyli błędem 500 dla losowego użytkownika. Stała kolejność czyni deadlock
   **strukturalnie niemożliwym**.
5. **Zwolnienie wygasłych blokad** na wybranych miejscach (`released_at = now()`).
   Gdy robią to dwie transakcje naraz, `UPDATE` zakłada lock na wierszu; druga
   po zwolnieniu locka ponownie ewaluuje `WHERE` na nowej wersji wiersza
   (EvalPlanQual), widzi wypełnione `released_at` i aktualizuje zero wierszy.
6. **Idempotencja.** Miejsca, które ta sesja już trzyma, są pomijane przy wstawianiu.
   Podwójne kliknięcie i retry po timeoucie kończą się sukcesem, nie błędem.
   TTL **nie jest odnawiany** — inaczej klient trzymałby fotel bez końca, pingując
   endpoint co minutę.
7. **Kontrola limitu** miejsc na sesję (nie na żądanie — inaczej wystarczyłoby
   wysłać dziesięć żądań po jednym miejscu i zablokować całą salę).
8. **Jeden INSERT na wszystkie miejsca.** Konflikt na którymkolwiek wywraca całe
   zapytanie i transakcję: blokowanie jest **all-or-nothing**, bo nie chcemy
   sprzedać trzech miejsc z pięciu i posadzić rodziny osobno.
9. **Sprawdzenie sprzedanych biletów.** Bilety są w innej tabeli, więc indeks
   blokad ich nie pilnuje. Kontrola idzie **po** INSERT-cie: skoro nasz wpis
   przeszedł, nikt nie trzyma aktywnej blokady tego miejsca, a bilet powstaje
   wyłącznie z aktywnej blokady — więc nikt nie jest właśnie w trakcie finalizacji.

Po `23505` transakcja jest wycofywana, a dopiero **poza nią** serwis odpytuje bazę,
które konkretnie miejsca są zajęte, i zwraca je klientowi. W PostgreSQL transakcja
po błędzie jest zatruta (`25P02`) i nie wykonałaby żadnego kolejnego zapytania.

### Błędy domenowe zamiast wycieków `QueryException`

Serwis nie wie nic o HTTP i nie zwraca response'ów — rzuca wyjątki opisujące problem
biznesowy. Jedno miejsce w `bootstrap/app.php` (Etap 3) przetłumaczy je na spójny JSON,
dzięki czemu ten sam wyjątek obsłuży REST API, komendę konsolową i Livewire.
`QueryException` nigdy nie wychodzi na zewnątrz — niósłby fragment SQL-a, czyli wyciek
szczegółów implementacji.

| Kod błędu | HTTP | Kiedy |
|---|---|---|
| `SEATS_UNAVAILABLE` | 409 | miejsce trzyma inna sesja albo jest sprzedane |
| `SCREENING_NOT_BOOKABLE` | 409 | seans odwołany, zakończony lub już się zaczął |
| `EMPTY_SEAT_SELECTION` | 422 | pusta lista miejsc |
| `DUPLICATE_SEATS` | 422 | to samo miejsce podane dwa razy |
| `SEATS_NOT_IN_HALL` | 422 | miejsce nie istnieje lub jest z innej sali |
| `SEATS_INACTIVE` | 422 | miejsce wyłączone ze sprzedaży |
| `SEAT_LOCK_LIMIT_EXCEEDED` | 422 | przekroczony limit miejsc na sesję |

Rozróżnienie 409 od 422 jest celowe: **409** to konflikt stanu zasobu na serwerze
(żądanie było poprawne), **422** to błąd w danych żądania.

### Zwalnianie i scheduler

- `release()` — zwalnia wskazane miejsca; wymaga zgodności `session_id`, więc znajomość
  identyfikatora miejsca nie wystarcza do zwolnienia cudzej blokady. Idempotentne:
  powtórne odkliknięcie zwraca 0, a nie błąd. Nie rusza blokad wpiętych w rezerwację
  (`booking_id`) — te należą do procesu płatności.
- `releaseSession()` — porzucenie koszyka, zwalnia wszystko, co sesja trzyma na seansie.
- `sweepExpired()` — oznacza wygasłe blokady jako zwolnione, porcjami
  (`SEAT_LOCK_SWEEP_BATCH`, domyślnie 500), żeby jeden przebieg nie zakładał locków
  na dziesiątkach tysięcy wierszy. Dwa kroki, bo PostgreSQL nie zna `UPDATE ... LIMIT`.
- Komenda `cinema:seat-locks:sweep` w harmonogramie co minutę,
  z `withoutOverlapping()` i `runInBackground()`.

Blokady nigdy nie są usuwane — `released_at` zamiast `DELETE` daje ślad audytowy:
widać, kto trzymał miejsce i kiedy je puścił.

### Testy

```bash
php artisan test                        # całość
php artisan test --group=concurrency    # tylko test obowiązkowy
```

Baza testowa `cinema_testing` powstaje **automatycznie** przy pierwszym
`docker compose up` (skrypt `docker/postgres/init/01-create-test-database.sql`
wykonywany przez obraz postgres z `/docker-entrypoint-initdb.d/`). Nie ma żadnego
kroku ręcznego przed uruchomieniem testów.

**Testy nie działają na SQLite — i nie mogą.** SQLite nie zna częściowych indeksów
(`CREATE UNIQUE INDEX ... WHERE`) ani `EXCLUDE USING gist`, a `:memory:` to jedno
połączenie w jednym procesie. Test współbieżności musiałby więc testować coś innego
niż produkcja, co czyniłoby go bezwartościowym. `phpunit.xml` wskazuje PostgreSQL,
a osobny test-bezpiecznik (`DatabaseEnvironmentTest`) pilnuje, żeby nikt nie uruchomił
czyszczących testów na bazie deweloperskiej.

**Test obowiązkowy** uruchamia 20 procesów systemowych przez `proc_open()`.
Nie użyto `pcntl_fork()`, bo rozszerzenie `pcntl` nie jest domyślnie w obrazie
`php:8.4-fpm` (recruiter musiałby przebudować kontener), a procesy potomne
dziedziczyłyby po rodzicu to samo połączenie PDO. Każdy proces boot-uje Laravel od
zera i otwiera własne połączenie — izolacja identyczna z produkcyjną. Wspólna
**bariera startu** (znacznik `microtime` przekazywany argumentem) sprawia, że wszystkie
uderzają w bazę równocześnie, zamiast po kolei w miarę startowania.

Test dowodzi trzech rzeczy:

1. przy 20 równoczesnych żądaniach na to samo miejsce powstaje **dokładnie jedna**
   blokada, a 19 pozostałych dostaje `SEATS_UNAVAILABLE` / 409 — żaden nie kończy
   się wyjątkiem technicznym;
2. równoczesne żądania na **różne** miejsca wszystkie się udają (blokada nie jest
   globalnym mutexem na seans);
3. blokowanie nachodzących na siebie par miejsc `{i, i+1}` w pierścieniu nie powoduje
   deadlocków — dzięki deterministycznej kolejności sortowania.

Test współbieżności używa `DatabaseTruncation`, a nie `RefreshDatabase`: ten drugi
opakowuje test w transakcję, a dane niezatwierdzone są **niewidoczne dla innych
połączeń** — procesy potomne nie zobaczyłyby ani seansu, ani miejsc i test byłby
fałszywie zielony. Z tego samego powodu klasa sprząta po sobie w `tearDown()`:
zapisuje dane naprawdę, więc nie ma transakcji, która by je cofnęła.

### Konfiguracja

```env
SEAT_LOCK_TTL=600                    # czas życia blokady w sekundach
SEAT_LOCK_MAX_SEATS=10               # limit miejsc na sesję i seans
SEAT_LOCK_SWEEP_BATCH=500            # rozmiar porcji przy czyszczeniu
```

### Znane ograniczenia i co dalej

- **Brak endpointów HTTP** — serwis jest gotowy, ale API ścieżki zakupowej powstaje
  w Etapie 3. Wtedy dojdzie `FormRequest`, mapowanie wyjątków na JSON w
  `bootstrap/app.php`, rate limiting na endpointach blokowania i przekazywanie
  identyfikatora sesji nagłówkiem.
- **Kontener `scheduler` jeszcze nie istnieje** — komenda `cinema:seat-locks:sweep`
  jest zarejestrowana i przetestowana, ale nic nie wywołuje `schedule:run` co minutę.
  Kontener dojdzie razem z workerem kolejek w Etapie 5.
- **Migracje nie uruchamiają się same** przy `docker compose up` — po starcie trzeba
  wykonać `php artisan migrate --seed`. Docelowo trafi to do entrypointu kontenera PHP.
- **Brak broadcastu** — zmiana zajętości miejsca nie jest jeszcze rozgłaszana przez
  WebSocket. Reverb w Etapie 6; miejsce na `SeatLocked` / `SeatReleased` jest już
  wyznaczone w `SeatLockService`.
- **Bariera startu w teście to 3 sekundy** — na wolniejszej maszynie część procesów
  może wystartować już po niej. Test pozostaje poprawny (asercje dotyczą wyniku,
  nie czasu), ale kontencja jest wtedy słabsza. Docelowo lepszym rozwiązaniem byłaby
  bariera na tabeli w bazie albo na Redisie.
- **Gdybym miał więcej czasu**: dorzuciłbym test z `pg_sleep()` wstrzykniętym między
  zwolnienie wygasłej blokady a INSERT, żeby udowodnić, że okno między tymi krokami
  jest faktycznie zamknięte transakcją, a nie tylko wąskie.

---

## Etap 3 — REST API ścieżki zakupowej

Publiczne API dla dwóch klientów: aplikacji webowej Vue (Etap 8) i mobilnej
Flutter (Etap 9). Obsługuje pełną ścieżkę od wyboru kina po wycenę koszyka.

### Wersjonowanie

Wersja siedzi w ścieżce (`/api/v1/...`), nadawana przez `apiPrefix`
w `bootstrap/app.php`. Nie w nagłówku `Accept`, bo:

- aplikacja Flutter w sklepie nie aktualizuje się na żądanie — musi istnieć
  możliwość zamrożenia `v1` i wystawienia `v2` obok,
- prefiks nadaje framework, zanim wczyta plik tras, więc nie da się
  przypadkiem dodać endpointu bez wersji (grupa `Route::prefix('v1')`
  takiej gwarancji nie daje — wystarczy dopisać trasę pod klamrą),
- daje się wywołać `curl`-em, zacache'ować po URL i pokazać w Scramble.

### Endpointy

| Metoda | Ścieżka | Uwagi |
|---|---|---|
| POST | `/auth/register` | limit 10/h per IP |
| POST | `/auth/login` | limit 5/min per (e-mail+IP) oraz 20/min per IP |
| POST | `/auth/logout` | kasuje token TEGO urządzenia |
| GET | `/auth/me` | |
| GET | `/cinemas` | grupowane po miastach, bez paginacji |
| GET | `/cinemas/{cinema}` | klucz: slug |
| GET | `/cinemas/{cinema}/screening-dates` | kalendarz |
| GET | `/cinemas/{cinema}/screenings?date=` | paginowany |
| GET | `/screenings/{screening}` | |
| GET | `/screenings/{screening}/seat-map` | stan każdego miejsca |
| GET | `/screenings/{screening}/seat-locks` | koszyk + timer |
| POST | `/screenings/{screening}/seat-locks` | 201 / 409, limit 30/min per sesja |
| DELETE | `/screenings/{screening}/seat-locks/{seat}` | odkliknięcie, idempotentne |
| DELETE | `/screenings/{screening}/seat-locks` | porzucenie koszyka (sendBeacon) |
| GET | `/bookings` | tylko własne, paginowane |
| GET | `/bookings/{reference}` | klucz: ULID, chronione Policy |

### Kształt odpowiedzi

Sukces — zawsze koperta `data`; listy dokładają `links` i `meta` z paginacji:

    { "data": { ... } }
    { "data": [ ... ], "links": { ... }, "meta": { ... } }

Koperta pozwala dołożyć `meta` do dowolnej odpowiedzi bez łamania kontraktu.
Nie ma pola `success` — status HTTP już to mówi, a dublowanie stanu w dwóch
miejscach kończy się tym, że któreś kłamie.

Błąd — jeden kształt dla wszystkiego:

    {
      "message": "Wybrane miejsca zostały właśnie zajęte.",
      "code": "SEATS_UNAVAILABLE",
      "context": { "seat_ids": [17, 18], "seats": ["B7", "B8"] },
      "errors":  { "pole": ["komunikat"] }
    }

- `message` — dla człowieka, po polsku, gotowe do wyświetlenia
- `code` — dla maszyny; frontend rozgałęzia się po nim, nigdy po treści
  komunikatu ani po samym statusie (409 ma dwie różne przyczyny)
- `context` — dane do reakcji UI, np. które fotele przemalować na czerwono
- `errors` — wyłącznie przy 422, w formacie zgodnym z Laravelem

### Kody błędów

| Kod | HTTP | Kiedy |
|---|---|---|
| `VALIDATION_FAILED` | 422 | błąd walidacji FormRequest |
| `UNAUTHENTICATED` | 401 | brak lub nieważny token |
| `INVALID_CREDENTIALS` | 401 | złe dane logowania |
| `FORBIDDEN` | 403 | Policy odmówiła dostępu do zasobu |
| `RESOURCE_NOT_FOUND` | 404 | adres poprawny, rekordu brak |
| `ENDPOINT_NOT_FOUND` | 404 | nie ma takiego adresu |
| `METHOD_NOT_ALLOWED` | 405 | zła metoda HTTP |
| `SEATS_UNAVAILABLE` | 409 | konflikt blokady miejsc |
| `SCREENING_NOT_BOOKABLE` | 409 | seans odwołany lub rozpoczęty |
| `PRICE_NOT_CONFIGURED` | 409 | brak ceny dla kategorii miejsca |
| `INVALID_SESSION_ID` | 422 | zły format nagłówka X-Session-Id |
| `TOO_MANY_REQUESTS` | 429 | przekroczony limit (+ Retry-After) |
| `SERVER_ERROR` | 500 | wszystko pozostałe, bez stack trace |

### Konwencje pól

- **Pieniądze**: `{ "amount": 3500, "currency": "PLN", "formatted": "35,00 zł" }`.
  `amount` to grosze jako `int`. Formatuje serwer, bo `Intl.NumberFormat`
  w przeglądarce i `NumberFormat` w Dartcie dają dla `pl_PL` różne wyniki.
- **Czas seansu**: ISO 8601 z offsetem, przeliczony do strefy KINA
  (`2026-09-11T18:30:00+02:00`). Klient wyświetla dosłownie — bez
  `toLocaleString()`. Widz w Londynie ma zobaczyć 18:30, godzinę z biletu.
- **Timer**: zawsze para `expires_at` + `expires_in_seconds`. Zegar telefonu
  bywa przestawiony; datą klient się resynchronizuje, sekundami odlicza.
- **Enum**: surowa wartość plus `*_label` po polsku, żeby klient nie
  utrzymywał własnego słownika tłumaczeń.

### Etap 3 — decyzje projektowe (14–31)

14. **Wersja API w ścieżce (`/api/v1`), nadawana przez `apiPrefix`.** Framework
    narzuca prefiks przed wczytaniem pliku tras, więc nie da się dodać endpointu
    bez wersji — nawet przez pomyłkę.
15. **Identyfikator sesji zakupowej wydaje serwer.** 32 losowe znaki alfanumeryczne,
    nagłówek `X-Session-Id` w żądaniu i w odpowiedzi. Klient generujący własny
    identyfikator mógłby wysłać `abc` i wejść w cudzy koszyk. Zły format to 422,
    a nie ciche wydanie nowego — inaczej klient z zepsutym localStorage gubiłby
    blokady bez żadnego sygnału.
16. **Sanctum z tokenami bearer, nie sesja cookie.** Jedno API dla Vue i Fluttera;
    aplikacja mobilna nie ma ciasteczek przeglądarki.
17. **Wylogowanie kasuje tylko `currentAccessToken()`.** `$user->tokens()->delete()`
    wyrzuciłoby użytkownika także z telefonu.
18. **Nieudane logowanie zawsze zwraca ten sam komunikat** (ochrona przed user
    enumeration). Przy nieistniejącym koncie wykonujemy hashowanie „na pusto”,
    żeby czas odpowiedzi nie zdradzał, czy konto istnieje.
19. **`role` poza `#[Fillable]`, a `AuthService` ustawia `UserRole::Customer` jawnie.**
    Dwie niezależne bariery przed privilege escalation przez masowe przypisanie.
20. **Jeden kształt błędu z polem `code`.** Frontend rozgałęzia się po `code`,
    a nie po statusie HTTP (409 ma co najmniej dwie różne przyczyny) ani po treści
    komunikatu, która może się zmienić przy tłumaczeniu.
21. **`dontReport(CinemaException::class)`.** Zajęte miejsce to normalny wynik
    biznesowy, nie awaria. Bez tego log tonąłby w tysiącach wpisów przy każdej
    premierze, a prawdziwe błędy ginęłyby w szumie.
22. **Logi na stderr (`LOG_CHANNEL=stderr`), nie do pliku** — zgodnie z 12-factor
    app; logi zbiera Docker. Przy okazji znika problem uprawnień do
    `storage/logs` między CLI (uid 1000) a PHP-FPM.
23. **`Money` jako typ wartościowy, formatowanie po stronie serwera.** Kwoty
    w groszach (int), pole `formatted` liczy backend, bo `Intl` w przeglądarce
    i `NumberFormat` w Darcie dają dla `pl_PL` różne wyniki (spacje, separatory).
24. **Czasy seansów w strefie kina, ISO 8601 z offsetem.** Widz w Londynie
    kupujący bilet do Krakowa ma zobaczyć godzinę z biletu, nie swoją lokalną.
25. **Enum jako surowa wartość + osobne pole `*_label` po polsku.** Klient
    rozgałęzia się po wartości, wyświetla etykietę i nie utrzymuje słownika
    tłumaczeń w dwóch aplikacjach.
26. **Plan sali nie ujawnia, czyja jest blokada** (`held` vs `held_by_you`).
    Ten sam payload pójdzie przez Reverb, a wymóg 1.3 zabrania danych osobowych
    w broadcastach.
27. **Wycena koszyka wyłącznie po stronie serwera (`CartPricingService`).** Serwis
    nie przyjmuje żadnej kwoty z zewnątrz. Brak ceny dla kategorii to wyjątek
    `PRICE_NOT_CONFIGURED`, nigdy ciche zero — darmowy bilet jest gorszy niż błąd.
28. **Limit blokowania miejsc kluczowany sesją zakupową, nie IP.** Klienci
    w galerii handlowej dzielą jedno publiczne IP za NAT-em; limit per IP
    zablokowałby wszystkich naraz.
29. **Policy na poziomie zasobu + ULID w URL.** Route model binding znajduje rekord
    niezależnie od tego, kto pyta — jedyną barierą przed IDOR jest
    `Gate::authorize()`. ULID utrudnia zgadywanie, ale nie jest zabezpieczeniem.
30. **`preventLazyLoading()` poza produkcją.** Problem N+1 objawia się jako błąd
    w teście, a nie jako 300 zapytań na produkcji.
31. **Cache repertuaru świadomie przesunięty do Etapu 7**, razem z inwalidacją przy
    zmianach w panelu admina. Dziś dałoby się napisać tylko cache na TTL — czyli
    dokładnie to, co zadanie odradza. `RepertoireService` jest jedynym miejscem
    odczytu repertuaru, więc podmiana dotknie dwóch metod, a nie kontrolerów.

### Etap 3 — pułapki, na które trafiliśmy (F–I)

- **F. `use Throwable;` w pliku bez namespace** (`bootstrap/app.php`) daje Warning
  „The use statement with non-compound name has no effect”. `php -l` tego nie
  wykrywa, a ostrzeżenie wypisuje się przed nagłówkami i psuje status HTTP.
  Rozwiązanie: w pliku bez namespace pisać `\Throwable` bez `use`.
- **G. Domknięcia w `with()` i `withCount()` dostają różne obiekty.**
  `with(['rel' => fn ($q) => ...])` dostaje obiekt relacji (`BelongsTo`,
  `HasMany`), a `withCount(['rel as alias' => fn ($q) => ...])` dostaje
  `Builder`. Otypowanie pierwszego jako `Builder` kończy się `TypeError`.
- **H. Laravel nie przebudowuje aplikacji między żądaniami w jednym teście.**
  Guard cachuje rozwiązanego użytkownika, więc test unieważnienia tokenu musi
  wywołać `$this->app['auth']->forgetGuards()` między żądaniami — inaczej drugie
  żądanie „przejdzie” z usuniętym tokenem.
- **I. Rate limiter trzyma liczniki w cache.** Bez `Cache::flush()` w `setUp()`
  drugie uruchomienie pakietu w ciągu minuty startuje ze zużytym limitem
  i testy padają losowo.

### Etap 3 — testy

| Klasa testu | Liczba | Obszar |
|---|---:|---|
| `AuthApiTest` | 8 | rejestracja, logowanie, wylogowanie, ochrona roli |
| `SeatLockApiTest` | 9 | blokowanie przez API: 409, idempotencja, `X-Session-Id`, limit |
| `CatalogApiTest` | 7 | kina, repertuar dnia, plan sali |
| `BookingAuthorizationTest` | 6 | policy rezerwacji, dostęp do cudzej rezerwacji (IDOR) |
| `CartPricingServiceTest` | 5 | wycena koszyka po stronie serwera, brak ceny |
| Etapy 1–2 | 29 | ograniczenia bazy, `SeatLockService`, test współbieżności |
| **Razem** | **64** | |

Uruchomienie całego pakietu (baza `cinema_testing` z `phpunit.xml`):

    art test

Testy integracyjne sprawdzają kontrakt API, nie implementację: status HTTP,
pole `code` błędu i kształt `data`. Dzięki temu refaktoryzacja serwisu nie
wymaga przepisywania testów, a zmiana kontraktu od razu je czerwieni.

## Etap 4 — płatności Stripe, webhook, wyścig przy płatności

Ścieżka zakupowa działa od kliknięcia miejsca do pobrania pieniędzy:
koszyk zamienia się w rezerwację, rezerwacja w płatność, a potwierdzona
płatność w bilety. Nieopłacone rezerwacje wygasają same i zwalniają miejsca.

Powstały: `config/payments.php`, warstwa `app/Payments` (interfejs
`PaymentGateway`, adapter Stripe'a, DTO, enum statusów), `BookingService`,
`PaymentService`, middleware `VerifyStripeSignature`, kontrolery checkoutu
i webhooka, komenda `cinema:bookings:expire`, tabela `stripe_webhook_events`
oraz 9 testów.

### Metoda integracji: Payment Intents + Payment Element / PaymentSheet

Do wyboru były Checkout (przekierowanie), Payment Element (osadzony)
i własny formularz karty. Wybrałem **PaymentIntent tworzony na serwerze**,
konsumowany przez Payment Element w Vue i PaymentSheet we Flutterze.

| Kryterium | Checkout | **Payment Element** | Własny formularz |
|---|---|---|---|
| Wspólny backend dla weba i mobile | webview + deep link | **jeden `client_secret`** | dwie implementacje |
| Timer 10 minut | sesja wygasa najwcześniej po 30 min | pełna kontrola | pełna kontrola |
| Zakres PCI DSS | minimalny | minimalny | najszerszy (SAQ D) |
| BLIK, Apple/Google Pay, 3-D Secure | tak | tak | ręcznie |

Rozstrzygnęły dwa argumenty. Po pierwsze, `flutter_stripe` przyjmuje ten sam
`client_secret` co Payment Element, więc backend nie wie, kto pyta.
Po drugie, czas: sesja Checkout wygasa najwcześniej po 30 minutach, a nasza
blokada miejsca po 10 — trzeba by synchronizować dwa niezależne zegary.

PaymentIntent tworzymy przed pokazaniem formularza (nie w trybie deferred),
bo BLIK nie działa, gdy dane płatności zbiera się przed utworzeniem intentu.

### Wyścig przy płatności

Problem z zadania: co, jeśli blokada miejsca wygaśnie dokładnie wtedy, gdy
klient finalizuje płatność?

```text
10:00:00  A blokuje miejsce H7 (TTL 10 min)
10:09:30  A klika "Zapłać", bank pokazuje 3-D Secure
10:10:00  blokada wygasa, scheduler zwalnia miejsce
10:10:05  B blokuje H7
10:10:40  A zatwierdza w aplikacji banku — płatność OK
10:10:42  webhook: "zapłacone" za miejsce, które ma już B
```

Problem nie sprowadza się do zegara. Webhook to wiadomość sieciowa
z opóźnieniem i ponowieniami, a w chwili płatności nasz serwer w niej nie
uczestniczy — rozmawiają ze sobą przeglądarka, bank i operator płatności.

Rozważone warianty:

| Wariant | Dlaczego sam nie wystarcza |
|---|---|
| Wydłużenie blokady na czas płatności | Zmniejsza okno, nie zamyka go |
| Blokada bez limitu do czasu webhooka | Porzucone płatności trzymają salę w nieskończoność |
| Weryfikacja w webhooku + automatyczny zwrot | Pieniądze zostały pobrane, czyli dokładnie to, czego zadanie zabrania |
| **Autoryzacja bez pobrania + weryfikacja + capture albo anulowanie** | Działa tylko dla metod ze wsparciem dla ręcznego capture (BLIK go nie ma) |

#### Wybrane rozwiązanie

Karty i portfele dostają `payment_method_options[card][capture_method]=manual`,
czyli **autoryzację bez pobrania**: bank blokuje środki, ale pieniądze nie
zmieniają właściciela. BLIK zostaje przy pobraniu automatycznym (nie wspiera
ręcznego capture), a jego ścieżkę ratunkową stanowi automatyczny zwrot.
Ustawienie jest per metoda płatności, bo globalne `capture_method=manual`
sprawiłoby, że Stripe w ogóle nie pokazałby klientowi BLIK-a.

Całość opiera się na dwóch regułach.

**Reguła 1: najpierw miejsce, potem pieniądze.** Proces ma dwa kroki i każdy
da się cofnąć, ale nie tak samo tanio. Cofnięcie biletu jest natychmiastowe,
darmowe i niewidoczne dla klienta. Cofnięcie płatności to zwrot: widoczny na
wyciągu, powolny i generujący zgłoszenia do obsługi. Dlatego najpierw
w jednej transakcji zamieniamy blokady w bilety (gwarancją jest indeks
`tickets_active_seat_unique`), a dopiero po jej zatwierdzeniu wołamy capture.
Nigdy odwrotnie.

**Reguła 2: o tym, czy miejsce jest nasze, decyduje wiersz w bazie, a nie
zegar.** Przy wystawianiu biletów NIE sprawdzamy `expires_at`. Sprawdzamy pod
`SELECT ... FOR UPDATE`, czy blokady rezerwacji nadal mają `released_at IS NULL`.

- Rezerwacja wygasła minutę temu, ale nikt nie zwolnił blokad? Honorujemy
  płatność — wygasła, lecz niezwolniona blokada nadal zajmuje indeks
  częściowy, więc nikt inny nie mógł tego miejsca zająć.
- Scheduler zdążył zwolnić blokady? Nie honorujemy, nawet sekundę po terminie.

Kompletność miejsc sprawdzamy przez porównanie sumy cen trzymanych foteli
z kwotą rezerwacji. Gdy czegokolwiek brakuje, suma się nie zgadza i nie
powstaje ani jeden bilet.

#### Maszyna stanów rezerwacji

```text
                         checkout (koszyk → rezerwacja)
                                    │
          ┌──────────────────── PENDING ─────────────────────┐
          │                        │                         │
   minął expires_at        amount_capturable_updated   payment_intent.canceled
   (scheduler)             albo succeeded (BLIK)              │
          ▼                        ▼                          ▼
  EXPIRED + anuluj PI     zajęcie miejsc w transakcji      CANCELLED
                          ┌────────┴─────────┐
                     udało się          nie udało się
                          │                  │
                    PAID + capture     karta → anuluj PI → EXPIRED
                                       BLIK  → zwrot     → REFUNDED
```

`payment_intent.payment_failed` nie zwalnia miejsc: PaymentIntent wraca do
stanu `requires_payment_method`, a klient może poprawić dane karty w oknie
płatności. Literówka w CVC nie może kosztować go miejsc — to świadome
odejście od dosłownego brzmienia zadania.

| Sytuacja | Wynik |
|---|---|
| Płatność w oknie | bilety, `paid`, capture |
| Autoryzacja po terminie, blokady nietknięte | bilety, `paid` — płatność późna, ale bezpieczna |
| Autoryzacja po przejęciu miejsca przez kogoś innego | brak biletów, anulowanie autoryzacji, **zero obciążenia** |
| To samo, ale BLIK (pobranie automatyczne) | brak biletów, automatyczny zwrot, `refunded` |
| To samo zdarzenie webhooka dwa razy | drugie nic nie zmienia, biletów tyle samo |
| Awaria między wystawieniem biletów a capture | Stripe ponawia zdarzenie, capture wykonuje się z tym samym kluczem idempotencji |
| Karta odrzucona | `pending`, miejsca trzymane do końca okna |

#### Idempotencja — trzy warstwy

1. **Tabela `stripe_webhook_events`** — pamięć o przetworzonych zdarzeniach
   i ślad audytowy (identyfikatory, typ, wynik; **bez treści zdarzenia**, bo
   payload zawiera dane osobowe płacącego). Wiersz powstaje PO przetworzeniu:
   zapis na wejściu sprawiłby, że przerwana obsługa zostałaby przy ponowieniu
   uznana za wykonaną.
2. **Maszyna stanów pod `SELECT ... FOR UPDATE`** na wierszu rezerwacji —
   właściwy zamek. Webhook i scheduler biorą ten sam wiersz, więc wykonują
   się po kolei i nie ma stanu pośredniego.
3. **`UNIQUE (screening_id, seat_id) WHERE status <> 'cancelled'`** na
   biletach — ostatnia linia obrony przed podwójną sprzedażą.

Po stronie operatora dochodzą **deterministyczne klucze idempotencji**
(`booking:{ULID}:create-intent`, `:capture`, `:cancel`, `:refund`). Klucz
losowy nie chroniłby przed ponowieniem po awarii, bo nowy proces wylosowałby
nowy. Podwójne kliknięcie "Zapłać" zatrzymują niezależnie: zamek na blokadach
w checkoucie, zapisane `stripe_payment_intent_id` i ten właśnie klucz.

#### Nowe endpointy

| Metoda | Ścieżka | Opis |
|---|---|---|
| POST | `/api/v1/screenings/{screening}/booking` | koszyk → rezerwacja + płatność; 201 przy pierwszym wywołaniu, 200 przy powtórzeniu |
| POST | `/api/v1/webhooks/stripe` | zdarzenia operatora; bez auth i bez CSRF, chroniony wyłącznie podpisem |

Checkout nie przyjmuje **żadnych danych w ciele żądania**: miejsca wynikają
z blokad sesji zakupowej, kwota z cennika seansu. Nowe kody błędów:
`EMPTY_CART` (422), `BOOKING_ALREADY_PENDING` (409), `BOOKING_NOT_PAYABLE`
(409), `PAYMENT_REJECTED` (402), `PAYMENT_PROVIDER_UNAVAILABLE` (503),
`INVALID_WEBHOOK_SIGNATURE` (400).

#### Konfiguracja

Klucze trybu testowego w `.env` (wzorzec w `.env.example`):
`STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`,
`STRIPE_WEBHOOK_TOLERANCE` (300 s, ochrona przed powtórzeniem podsłuchanego
żądania), `PAYMENT_WINDOW_SECONDS` (domyślnie 600). Adapter odmawia startu,
gdy poza produkcją poda mu się klucz inny niż `sk_test_`.

Podpis weryfikujemy na surowym ciele żądania (`$request->getContent()`);
ponowne zakodowanie JSON-a zmienia bajty i unieważnia podpis. Odrzucone
żądanie trafia do logu jawnym `Log::warning`, bo `CinemaException` jest
w `dontReport()`, a próba podszycia się pod operatora to sygnał
bezpieczeństwa, nie normalny wynik biznesowy.

#### Testy Etapu 4

| Klasa | Testy | Obszar |
|---|---:|---|
| `StripeWebhookTest` | 5 | podpis podrobiony, brak podpisu, przeterminowany znacznik czasu, bilety po autoryzacji, brak duplikatów przy powtórzonym zdarzeniu |
| `PaymentRaceTest` | 4 | utrata miejsca w trakcie płatności, płatność późna przy nietkniętych blokadach, wygaszanie rezerwacji, nieudana próba zapłaty |

W testach podmieniamy wyłącznie operacje pieniężne. Weryfikacja podpisu
przechodzi przez prawdziwy kod, bo test atrapy kryptografii niczego nie dowodzi.

#### Znane ograniczenia

- Gdyby `capture` nie powiódł się trwale, rezerwacja zostaje `paid` bez
  pobranych pieniędzy do czasu zdarzenia `payment_intent.canceled`.
  Docelowo przydałaby się komenda uzgadniająca stan z operatorem.
- Zwroty przy BLIK-u pozostają jedyną ścieżką, w której klient widzi
  obciążenie i zwrot, a nie tylko blokadę środków.
- `capture` wołamy synchronicznie w obsłudze webhooka; po wdrożeniu kolejek
  (Etap 5) naturalne będzie przeniesienie go do zadania w tle.
