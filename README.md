# System rezerwacji biletów kinowych

Pełna ścieżka sprzedaży biletów dla sieci kin: wybór kina i seansu, interaktywny
plan sali z atomową blokadą miejsc w czasie rzeczywistym, płatność Stripe,
bilety z kodem QR w PDF, panel administracyjny oraz aplikacja mobilna.

## Stack

| Warstwa | Technologia | Uzasadnienie |
|---|---|---|
| Backend | Laravel 13, PHP 8.4 | wymóg zadania |
| Baza danych | PostgreSQL 16 | patrz niżej |
| Cache, sesje, kolejka | Redis 7 (AOF) | jeden broker dla cache i kolejki, patrz Etap 5 |
| Serwer WWW | nginx + PHP-FPM (Alpine) | |
| Zadania w tle | kontenery `worker` (`queue:work`) i `scheduler` (`schedule:work`) | |
| WebSocket | Laravel Reverb (protokół Pushera) za nginx, kontener `reverb` | patrz Etap 6 |
| Panel administracyjny | Livewire 4 + Alpine, Pico CSS, bez kroku budowania | patrz Etap 7 |
| Płatności | Stripe (Payment Intents, `stripe/stripe-php`) | patrz Etap 4 |
| Bilety | `endroid/qr-code` (QR), `dompdf/dompdf` (PDF) | patrz Etap 5 |
| Poczta w środowisku deweloperskim | Mailpit | następca nierozwijanego Mailhoga |
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

# Klucz podpisu kodów QR (bez niego aplikacja nie wystawi biletu).
sed -i "s/^TICKET_QR_KEY=$/TICKET_QR_KEY=$(openssl rand -hex 32)/" backend/.env
# Klucze Reverba (WebSocket): identyfikator aplikacji, klucz publiczny i sekret podpisu.
sed -i "s/^REVERB_APP_ID=.*/REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)/" backend/.env
sed -i "s/^REVERB_APP_KEY=.*/REVERB_APP_KEY=$(openssl rand -hex 10)/" backend/.env
sed -i "s/^REVERB_APP_SECRET=.*/REVERB_APP_SECRET=$(openssl rand -hex 20)/" backend/.env
# Klucze trybu testowego Stripe'a: STRIPE_PUBLISHABLE_KEY, STRIPE_SECRET_KEY
# uzupełnij ręcznie w backend/.env (Dashboard Stripe → Developers → API keys).

# Worker i scheduler działają jako www-data (uid 82) i zapisują do storage/.
mkdir -p backend/storage/app/private/tickets backend/storage/app/public backend/storage/fonts
chmod -R a+rwX backend/storage backend/bootstrap/cache
# Plakaty filmów spod /storage (Etap 7): względne dowiązanie, działa w WSL i w kontenerze.
ln -s ../storage/app/public backend/public/storage

docker compose up --build -d
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
docker compose restart worker scheduler reverb
```

Kroki po `docker compose up` trafią do entrypointu kontenera w Etapie 10
(wymóg „zero kroków ręcznych").

| Adres | Co |
|---|---|
| <http://localhost:8080/api/v1> | REST API |
| <http://localhost:8080/docs/api> | dokumentacja API (Scramble) |
| <http://localhost:8080/admin> | panel administracyjny (administrator i obsługa kina), patrz Etap 7 |
| <http://localhost:8025> | Mailpit — cała poczta wysłana przez aplikację |
| `ws://localhost:8080/app/{REVERB_APP_KEY}` | WebSocket (Reverb przez nginx), patrz Etap 6 |

Webhooki Stripe'a lokalnie (Stripe CLI, osobny terminal):

```bash
stripe listen --forward-to localhost:8080/api/v1/webhooks/stripe
# wypisany sekret whsec_... wpisz do STRIPE_WEBHOOK_SECRET w backend/.env
```

Konta testowe (hasło `password`):

| E-mail | Rola |
|---|---|
| admin@cinema.test | administrator |
| anna@cinema.test | klient |
| piotr@cinema.test | klient |
| maria@cinema.test | klient |
| obsluga.warszawa@cinema.test | obsługa kina (Kino Atlantyk) |
| obsluga.krakow@cinema.test | obsługa kina (Kino Wisła) |
| obsluga.gdansk@cinema.test | obsługa kina (Kino Bałtyk) |

## Struktura repozytorium

```text
cinema/
├── docker-compose.yml        php, worker, scheduler, reverb, nginx, postgres, redis, mailpit
├── docker/
│   ├── nginx/default.conf    /app/ do Reverba, reszta poza public/ do PHP-FPM
│   ├── php/Dockerfile        PHP 8.4-FPM Alpine: pdo_pgsql, redis, gd, intl, pcntl, zbar
│   ├── php/conf.d/uploads.ini  (Etap 7) limity wysyłania plików PHP, podpięte jako wolumen
│   └── postgres/init/        tworzy bazę cinema_testing przy pierwszym starcie wolumenu
├── backend/                  aplikacja Laravel (API, kolejki, scheduler)
│   ├── app/
│   │   ├── Services/         logika biznesowa: blokady, rezerwacje, płatności, bilety
│   │   ├── Services/Admin/   (Etap 7) logika panelu: kina, sale, układy, filmy, seanse, artykuły, pulpit
│   │   ├── Livewire/Admin/   (Etap 7) komponenty panelu — tylko dane formularza i wywołanie serwisu
│   │   ├── Support/          CatalogCache, ScreeningTimeline, ArticleMarkdown i inne klocki bez stanu
│   │   ├── Payments/         port PaymentGateway i jedyny adapter znający Stripe'a
│   │   ├── Tickets/          podpis i obraz kodu QR, PDF, zapis PDF-ów
│   │   ├── Http/             kontrolery (tylko HTTP), FormRequesty, zasoby JSON
│   │   ├── Jobs/, Listeners/, Notifications/, Events/   praca w tle
│   │   ├── Policies/         autoryzacja na poziomie zasobu
│   │   └── Exceptions/       wyjątki domenowe z kodem HTTP i polem code
│   ├── database/             migracje, fabryki, seedery
│   ├── routes/api.php        /api/v1
│   ├── routes/web.php        (Etap 7) /admin — panel administracyjny
│   ├── public/vendor/admin/  (Etap 7) przypięte Pico CSS, laravel-echo, pusher-js z sumami SHA256
│   ├── routes/console.php    harmonogram
│   └── tests/                PHPUnit na PostgreSQL (Unit, Feature)
├── tools/realtime-probe/     (Etap 6) sonda WebSocket: pusher-js w kontenerze Node
├── tools/admin-assets/       (Etap 7) pobieranie zasobów panelu: Node po digeście, npm ci
├── tools/readme-compliance/  (Etap 7) sprawdzenie, czy nazwy z README istnieją w kodzie
├── frontend/                 (Etap 8) Vue 3
└── mobile/                   (Etap 9) Flutter
```

## Model danych

```mermaid
erDiagram
    cinemas ||--o{ halls : ma
    halls ||--o{ seats : ma
    price_categories ||--o{ seats : "kategoria miejsca"
    movies ||--o{ screenings : ""
    halls ||--o{ screenings : ""
    screenings ||--o{ screening_prices : cennik
    price_categories ||--o{ screening_prices : ""
    screenings ||--o{ seat_locks : ""
    seats ||--o{ seat_locks : ""
    users ||--o{ bookings : ""
    screenings ||--o{ bookings : ""
    bookings ||--o{ tickets : ""
    seats ||--o{ tickets : ""
    cinemas |o--o{ users : "obsługa kina"
    bookings |o--o{ stripe_webhook_events : ""
    screenings ||--o| screening_seat_versions : "wersja stanu miejsc"
    movies |o--o{ articles : "premiera"
    users |o--o{ articles : "autor"
```

| Tabela | Rola | Najważniejsze ograniczenia |
|---|---|---|
| `cinemas` | kino: miasto, adres, **strefa czasowa**, `is_active` | `slug` UNIQUE |
| `halls` | sala: typy projekcji (`jsonb`), wymiary siatki planu | `(cinema_id, name)` UNIQUE |
| `seats` | miejsce: rząd, numer, typ, pozycja X/Y, kategoria cenowa | UNIQUE na `(hall, rząd, numer)` i na `(hall, x, y)`; CHECK typu |
| `price_categories` | standard, premium, VIP, loża — globalne dla sieci | `slug` UNIQUE |
| `movies` | tytuł, opis, czas trwania, kategoria wiekowa, gatunki (`jsonb`) | CHECK `duration_minutes > 0` |
| `screenings` | seans: `starts_at`, `ends_at`, `slot_ends_at`, projekcja, wersja językowa, status | **`EXCLUDE USING gist (hall_id =, tstzrange(starts_at, slot_ends_at) &&) WHERE status <> 'cancelled'`** |
| `screening_prices` | cena per seans i kategoria (grosze) | |
| `seat_locks` | tymczasowa blokada miejsca | **`UNIQUE (screening_id, seat_id) WHERE released_at IS NULL`** |
| `screening_seat_versions` | licznik wersji stanu miejsc per seans (Etap 6) | klucz główny = `screening_id`, CHECK `version > 0` |
| `bookings` | rezerwacja: `reference` (ULID), status, kwota, PaymentIntent, znaczniki powiadomień, anulowanie (powód, kto) i rozliczenie zwrotu (`refund_requested_at`, `refund_completed_at`) | CHECK statusu i `bookings_refund_completed_after_request`, `stripe_payment_intent_id` UNIQUE, indeksy częściowe `bookings_confirmation_pending`, `bookings_refund_pending`, `bookings_paid_at` |
| `tickets` | bilet: `code` (UUID v4), cena, status, `validated_at`, `validated_by_user_id` | **`UNIQUE (screening_id, seat_id) WHERE status <> 'cancelled'`**, `code` UNIQUE |
| `users` | klient, obsługa kina, administrator | CHECK `(role = 'staff') = (cinema_id IS NOT NULL)` |
| `stripe_webhook_events` | dziennik przetworzonych zdarzeń Stripe'a (bez treści) | klucz główny = `event_id` |
| `articles` | artykuł „Aktualności” / „Nadchodzące premiery” (Etap 7): typ, status, data publikacji, Markdown | `slug` UNIQUE, CHECK typu, statusu, daty publikacji i filmu premiery, indeks częściowy `articles_published` |

### Etap 1 — decyzje projektowe (1–13)

1. **Miejsca należą do sali, nie do seansu.** Plan sali definiuje się raz;
   stan miejsca na konkretnym seansie wynika z blokad i biletów, więc nie trzeba
   generować milionów wierszy „miejsce × seans" z góry.
2. **Love seat to jeden bilet z ceną pakietową.** Jedna pozycja na planie, jedna
   blokada, jeden kod QR — bez sklejania dwóch biletów, które trzeba by
   sprzedawać i anulować razem.
3. **`seats.type` i `price_category_id` to dwa niezależne wymiary.** Typ mówi,
   czym jest fotel (standard, podwójny, dla osób z niepełnosprawnością), kategoria
   — ile kosztuje. Miejsce dla osoby z niepełnosprawnością może być w strefie
   premium i odwrotnie.
4. **Pieniądze zawsze jako `integer` w groszach.** Żadnych `float` ani `decimal`
   w PHP; Stripe operuje na tych samych jednostkach.
5. **Seans ma trzy znaczniki czasu.** `starts_at`, `ends_at` (koniec filmu)
   i `slot_ends_at` (koniec sprzątania). Kolizje sal pilnuje `slot_ends_at`,
   a widz i raporty patrzą na `ends_at`.
6. **`tickets.screening_id` zdenormalizowane.** Bez tej kolumny indeks częściowy
   „jeden bilet na miejsce na seansie" nie miałby czego obejmować (seans jest
   w `bookings`, a indeks może obejmować tylko jedną tabelę).
7. **`seat_locks` używa `released_at` zamiast `DELETE`.** Ślad audytowy, a indeks
   częściowy i tak obejmuje tylko aktywne blokady.
8. **`bookings.user_id NOT NULL`.** Zakup wymaga konta; blokować miejsca można
   anonimowo (sesja zakupowa), ale płacić już nie.
9. **`bookings.reference` = ULID, `tickets.code` = UUID v4.** Numer rezerwacji jest
   sortowalny i czytelny dla supportu; kod biletu nie zdradza czasu zakupu.
   Żaden z nich nie jest sekwencyjny.
10. **PostgreSQL** — indeksy częściowe i `EXCLUDE` (patrz wyżej).
11. **Atomowość blokad na indeksie UNIQUE**, a nie na `SELECT ... FOR UPDATE`
    ani Redis `SETNX` (szczegóły w Etapie 2).
12. **Blokowanie all-or-nothing, `seat_id` sortowane rosnąco** — brak deadlocków
    i brak częściowo zajętych koszyków.
13. **Serwisy rzucają wyjątki domenowe**, a nie zwracają odpowiedzi HTTP; jedno
    miejsce tłumaczy je na JSON.

## Dane testowe

Seeder tworzy 3 kina w różnych miastach, 7 sal w trzech układach (z przejściami,
strefą Premium, rzędem VIP w sali IMAX, kanapami dla par w ostatnim rzędzie
i miejscami dla osób z niepełnosprawnością przy wejściu), 8 filmów,
repertuar od 2 dni wstecz do 13 dni w przód wraz z cennikami oraz jedno konto
obsługi na każde kino.

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

Ograniczenia poszczególnych etapów są opisane w ich sekcjach.

## Stan prac

- [x] Etap 0 — Docker Compose, szkielet Laravela
- [x] Etap 1 — model danych, migracje, modele Eloquent, seeder
- [x] Etap 2 — blokowanie miejsc i test współbieżności
- [x] Etap 3 — REST API ścieżki zakupowej
- [x] Etap 4 — Stripe, webhook, obsługa wyścigu przy płatności
- [x] Etap 5 — bilety, QR, PDF, kolejki, mail, scheduler
- [x] Etap 6 — WebSocket (Laravel Reverb)
- [x] Etap 7 — panel administracyjny (Livewire), cache w Redisie, moduł informacyjny
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
Nie użyto `pcntl_fork()`: procesy potomne dziedziczyłyby po rodzicu to samo
połączenie PDO i stan aplikacji, więc nie byłyby niezależnymi klientami bazy.
Rozszerzenie `pcntl` jest w obrazie (potrzebuje go `queue:work` do limitu czasu
zadań), ale do tego testu się nie nadaje. Każdy proces boot-uje Laravel od
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
- ~~Kontener `scheduler` jeszcze nie istnieje~~ — **rozwiązane w Etapie 5**:
  kontener `scheduler` uruchamia `schedule:work`, a `cinema:seat-locks:sweep`
  wykonuje się co minutę.
- **Migracje nie uruchamiają się same** przy `docker compose up` — po starcie trzeba
  wykonać `php artisan migrate --seed`. Docelowo trafi to do entrypointu kontenera PHP.
- ~~Brak broadcastu~~ — **rozwiązane w Etapie 6**: każda zmiana stanu miejsc
  podbija wersję w `SeatStateRecorder`, a po COMMIT wychodzi zdarzenie
  `seats.changed` na kanale seansu.
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
| POST | `/broadcasting/auth` | (Etap 6) podpis kanału prywatnego; token opcjonalny, limit 60/min per klient i 1200/min per IP |

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
| `CHANNEL_FORBIDDEN` | 403 | (Etap 6) brak dostępu do kanału WebSocket |
| `TOO_MANY_REQUESTS` | 429 | przekroczony limit (+ Retry-After) |
| `REALTIME_UNAVAILABLE` | 503 | (Etap 6) broadcaster nie potrafi podpisywać kanałów |
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
31. ~~**Cache repertuaru świadomie przesunięty do Etapu 7**, razem z inwalidacją przy
    zmianach w panelu admina. Dziś dałoby się napisać tylko cache na TTL — czyli
    dokładnie to, co zadanie odradza. `RepertoireService` jest jedynym miejscem
    odczytu repertuaru, więc podmiana dotknie dwóch metod, a nie kontrolerów.~~
    **Zrealizowane w Etapie 7** (decyzje 136–142): kontrolery i testy API bez zmian.

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

---

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
  Po Etapie 5 świadomie zostało synchronicznie — uzasadnienie w sekcji Etapu 5.

---

## Etap 5 — bilety, kody QR, PDF, kolejki, mail, scheduler

Po opłaceniu rezerwacji klient dostaje e-mail z PDF-em (jeden bilet na stronę,
każdy z kodem QR), może pobrać PDF i pojedyncze kody z historii zakupów,
a obsługa kina skanuje bilety przy wejściu. Wszystko, co wolne albo zawodne
(render PDF, SMTP), dzieje się w tle — webhook Stripe'a nie czeka na pocztę.

Powstały: kontenery `worker`, `scheduler` i `mailpit`, warstwa `app/Tickets`
(podpis tokenu, obraz QR, PDF, zapis PDF-ów), `app/Queue/RetryPolicy`,
zdarzenie `BookingPaid`, zadanie `GenerateBookingTicketsPdf`, powiadomienia
`BookingConfirmed` i `ScreeningReminder`, endpointy pobierania i walidacji
biletów, rola obsługi kina, trzy nowe komendy harmonogramu i 67 testów.

### Przepływ po płatności

```mermaid
sequenceDiagram
    participant S as Stripe
    participant W as Webhook (PHP-FPM)
    participant Q as Redis (kolejka)
    participant K as Worker
    participant M as SMTP (Mailpit)
    S->>W: payment_intent.amount_capturable_updated
    W->>W: bilety w transakcji, capture
    W->>W: BookingPaid (po COMMIT)
    W->>Q: GenerateBookingTicketsPdf
    W-->>S: 200 OK
    Q->>K: zadanie
    K->>K: render PDF, zapis atomowy
    K->>Q: BookingConfirmed (powiadomienie w kolejce)
    Q->>K: powiadomienie
    K->>M: e-mail z PDF-em
    K->>K: NotificationSent → confirmation_sent_at
```

1. `PaymentService` emituje `BookingPaid` **wyłącznie po udanym capture**
   (albo po wystawieniu biletów dla płatności pobranej automatycznie) —
   jedno źródło prawdy zamiast nasłuchiwania na zmianę kolumny `status`.
2. Zdarzenie implementuje `ShouldDispatchAfterCommit`: gdyby transakcja się
   wycofała, worker nie dostanie zadania dla rezerwacji, której w bazie nie ma.
3. Listener `QueueBookingConfirmation` jest synchroniczny i robi tylko
   `dispatch()` (milisekundy). Błąd kolejki łapie i loguje — **webhook zawsze
   odpowiada 2xx**, bo bilety już istnieją, a pieniądze są pobrane.
   Ponowienie zdarzenia przez Stripe'a niczego by nie naprawiło; naprawia to
   komenda ponawiająca potwierdzenia (niżej).
4. `GenerateBookingTicketsPdf` renderuje i zapisuje PDF, po czym wysyła
   powiadomienie. Zadanie jest idempotentne (`ShouldBeUnique` po id rezerwacji,
   zapis przez plik tymczasowy i `move`).
5. `BookingConfirmed` jest powiadomieniem w kolejce z własnym `shouldSend()`:
   nie wyśle się dla rezerwacji nieopłaconej ani już potwierdzonej.
6. Listener `MarkBookingConfirmationSent` na `NotificationSent` ustawia
   `confirmation_sent_at` dopiero po faktycznym wysłaniu.

### Kolejka: Redis, nie RabbitMQ

| Kryterium | **Redis** | RabbitMQ |
|---|---|---|
| Nowy element infrastruktury | nie — Redis już jest (cache, limitery, zamki) | nowy broker, konfiguracja, monitoring |
| Sterownik w Laravelu | wbudowany, Horizon w przyszłości | pakiet zewnętrzny |
| Opóźnienia (backoff) | natywnie (sorted set) | wtyczka `delayed_message_exchange` |
| `ShouldBeUnique`, `onOneServer`, `withoutOverlapping` | ten sam Redis jako magazyn zamków | i tak potrzebny Redis |
| Routing, wielu konsumentów, gwarancje potwierdzeń | podstawowe | mocna strona |

Rozstrzyga skala i koszt: kilkadziesiąt e-maili na minutę w szczycie premiery
to nic dla Redisa, a zamki dla harmonogramu i unikalnych zadań i tak w nim
żyją. RabbitMQ miałby sens przy wielu usługach wymieniających zdarzenia.
Ryzyko Redisa — utrata zadań przy restarcie — ogranicza włączony AOF.

### Ponawianie i nieudane zadania

Wszystkie zadania i powiadomienia korzystają z `RetryPolicy` (trait
`UsesRetryPolicy`):

| Parametr | Wartość | Dlaczego |
|---|---|---|
| Maksymalna liczba prób | 3 | wymóg zadania; SMTP, który nie działa trzy razy z rzędu, wymaga człowieka |
| Opóźnienia | 10 s, potem 40 s (podstawa 10 s, mnożnik 4) | wykładniczo: chwilowa czkawka mija po 10 s, restart usługi po minucie |
| Rozrzut (jitter) | ±20% | po awarii SMTP setki zadań nie wracają w tej samej sekundzie |
| `--timeout` workera | 60 s | zabija zawieszone zadanie |
| `retry_after` Redisa | 90 s | musi być **większe** niż timeout, inaczej zadanie wykona się dwa razy równolegle |
| `stop_grace_period` | 75 s | przy restarcie kontenera bieżące zadanie zdąży się skończyć |

`$tries` jest **właściwością**, a `backoff()` **metodą**: powiadomienia
i mailable w kolejce ignorują metodę `tries()` (pułapka Y).

Po trzeciej porażce zadanie trafia do `failed_jobs`, a `Queue::failing`
(w `QueueServiceProvider`) zapisuje wpis `error` z klasą zadania, UUID,
kolejką i **klasą** wyjątku — bez jego komunikatu, bo komunikat błędu SMTP
potrafi zawierać adres e-mail klienta. Pełny ślad zostaje w `failed_jobs`.

```bash
docker compose exec php php artisan queue:failed        # lista
docker compose exec php php artisan queue:retry all     # ponowienie po naprawie
```

### Kod QR — co jest w środku

```text
T1.9b2f4c1e-3a7d-4e8b-9c0f-1a2b3c4d5e6f.Xq3vB9kLmN0pR2sT
│  │                                    └─ 12 bajtów HMAC-SHA256, base64url
│  └─ tickets.code (UUID v4)
└─ wersja formatu
```

Rozważone warianty:

| Wariant | Problem |
|---|---|
| Sam UUID | każdy ciąg w formacie UUID trafia do bazy; brak wersjonowania |
| JWT z danymi biletu | długi (gęsty QR, gorzej czytelny z pękniętego ekranu telefonu), dane osobowe i miejsce w kodzie, zmiana seansu unieważnia wydruk |
| URL do API | skaner obsługi nie jest przeglądarką; adres zdradza infrastrukturę |
| **`T1.{uuid}.{mac}`** | krótki, podpisany, bez danych osobowych; stan biletu zawsze z bazy |

- **Podpis** odrzuca podrobione i zniekształcone kody bez zapytania do bazy
  (`hash_equals`, stały czas porównania).
- **Osobny klucz `TICKET_QR_KEY`**, nie `APP_KEY`: rotacja klucza aplikacji
  (sesje, szyfrowanie) nie może unieważnić sprzedanych biletów. Brak wartości
  domyślnej — aplikacja bez klucza odmawia wystawienia biletu, zamiast podpisać
  go pustym ciągiem. `phpunit.xml` ma własny klucz testowy.
- **MAC skrócony do 12 bajtów (96 bitów).** Weryfikacja jest wyłącznie online,
  za limiterem 120 prób na minutę, więc zgadnięcie podpisu jest niewykonalne.
  Skrót ma konkretny powód: token ma 56 znaków i mieści się w **QR wersji 6**
  (41×41 modułów). Przy pełnym MAC-u kod przechodził do wersji 7, która ma
  wzorzec wyrównania dokładnie na środku — pod logo. Test dekodujący obraz
  (`zbarimg`) padał, choć korekcja błędów teoretycznie powinna to wytrzymać.
- **Prefiks `T1`** zostawia miejsce na `T2` z podpisem Ed25519, gdyby skanery
  miały działać offline (klucz publiczny w skanerze zamiast sekretu HMAC).

Parametry obrazu (`endroid/qr-code` 6): korekcja błędów **High** (30%), logo
na 20% szerokości z wyciętym tłem, margines 10% (strefa ciszy), kodowanie
ISO-8859-1 (token jest czystym ASCII, a nagłówek ECI dla UTF-8 myli część
starszych skanerów), 480 px.

Kod biletu nie pojawia się w URL-ach, odpowiedziach API ani jako tekst w PDF-ie
— jedynym nośnikiem jest obraz QR, a `TicketResource` zwraca `qr_url`.
Test `TicketQrRendererTest` generuje PNG i **dekoduje go** `zbarimg`, bo test
sprawdzający tylko nagłówek PNG przepuściłby nieczytelny kod.

### PDF: dompdf

| Biblioteka | Dlaczego nie |
|---|---|
| wkhtmltopdf / Snappy | projekt zarchiwizowany, binarka z nieaktualnym WebKitem |
| Browsershot (Chromium) | kilkaset MB w obrazie, proces przeglądarki na każde zadanie |
| mPDF | licencja GPL-2.0 |
| **dompdf 3** | czysty PHP, LGPL, wystarczający CSS dla prostego układu biletu |

- **Czcionka DejaVu Sans** — wbudowane czcionki PDF (Helvetica) nie mają
  polskich znaków; zamiast „ą” byłoby „?”. Cache czcionek w `storage/fonts`.
- **Bezpieczne opcje**: wyłączone zasoby zdalne, JavaScript i PHP w szablonie.
  Tytuł filmu pochodzi z panelu admina; `<img src="http://...">` w opisie nie
  może zamienić renderera w narzędzie do skanowania sieci wewnętrznej.
- **Obrazy jako data URI** (QR i logo) — konsekwencja wyłączenia zasobów zdalnych.
- **Jeden bilet na stronę A4**: każdy bilet da się wydrukować i wręczyć
  osobno.
- **Godziny w strefie czasowej kina**, liczone przez `BookingTicketsPresenter`.
  Ten sam presenter przygotowuje dane dla PDF-a i treści e-maila, więc oba
  kanały nie mogą się rozjechać.
- **Zapis**: `storage/app/private/tickets`, najpierw plik tymczasowy, potem
  `move` — pobierający nigdy nie dostanie połowy pliku. Gdy pliku brak
  (worker jeszcze nie skończył, dysk wyczyszczony), `TicketPdfStore` renderuje
  PDF w locie.

### Poczta

Mailpit w kontenerze przechwytuje całą pocztę (<http://localhost:8025>).
Mailhog nie jest rozwijany od 2020 roku; Mailpit ma ten sam model pracy
i API do testów.

- `BookingConfirmed` — numer rezerwacji, seans, miejsca, PDF w załączniku
  (`bilety-{reference}.pdf`).
- `ScreeningReminder` — przypomnienie przed seansem, **bez załącznika**:
  bilety klient już ma, a kilkusetkilobajtowy PDF wysłany drugi raz tylko
  obciąża skrzynki i zwiększa ryzyko trafienia do spamu.

Gwarancje dostarczenia są różne i świadomie dobrane:

| Wiadomość | Gwarancja | Dlaczego |
|---|---|---|
| Potwierdzenie | **at-least-once** | brak biletów jest gorszy niż duplikat; worker, który padnie między SMTP a zapisem znacznika, wyśle je ponownie |
| Przypomnienie | **at-most-once** | brak przypomnienia to drobiazg, dwa przypomnienia wyglądają na błąd systemu |

### API biletów

| Metoda | Ścieżka | Kto | Opis |
|---|---|---|---|
| GET | `/api/v1/bookings/{booking}/tickets/pdf` | właściciel rezerwacji | PDF ze wszystkimi biletami |
| GET | `/api/v1/bookings/{booking}/tickets/{ticket}/qr` | właściciel rezerwacji | PNG kodu jednego biletu |
| POST | `/api/v1/tickets/validate` | obsługa kina, administrator | skanowanie przy wejściu |

- **Token Sanctum zamiast podpisanego URL-a.** Podpisany link zostaje w historii
  przeglądarki, logach proxy i można go przesłać dalej — a PDF to bilety na
  okaziciela. Aplikacja mobilna i tak ma token.
- **`scopeBindings()`** — bilet jest szukany wyłącznie wśród biletów rezerwacji
  z URL-a, więc id cudzego biletu daje 404, a nie cudzy kod QR.
- Odpowiedzi z biletami mają `Cache-Control: private, no-store`.
- Pobranie biletów rezerwacji, która nie jest opłacona: 409
  `BOOKING_TICKETS_UNAVAILABLE`.
- Limitery per użytkownik: `ticket-downloads` 30/min, `ticket-validation`
  120/min (bramka przy dużej sali to ok. 1–2 skany na sekundę).

#### Walidacja biletu

Kolejność sprawdzeń jest celowa — tańsze i niezdradzające informacji najpierw:

1. uprawnienie do seansu (policy `ScreeningPolicy::validateTickets`):
   administrator wszędzie, obsługa tylko w swoim kinie → 403;
2. format i podpis tokenu → 422 (bez zapytania do bazy);
3. bilet istnieje → 404;
4. bilet należy do skanowanego seansu → 409 z godziną właściwego seansu, żeby
   obsługa mogła pokierować widza do innej sali;
5. okno czasowe: od `TICKET_VALIDATION_OPENS_MINUTES` przed początkiem do końca
   filmu (`ends_at`) → 409;
6. **atomowy `UPDATE tickets SET status = 'used' … WHERE id = ? AND status = 'valid'`**;
7. gdy `UPDATE` nie zmienił wiersza, serwis czyta bilet ponownie i zwraca
   przyczynę: wykorzystany (z godziną pierwszego skanu) albo anulowany → 409.

Status biletu jest sprawdzany **wynikiem `UPDATE`**, a nie wcześniejszym
`SELECT`-em. Bramki przy dwóch wejściach skanujące ten sam zrzut ekranu
jednocześnie przejdą kroki 1–5, ale tylko jeden `UPDATE` zmieni wiersz — drugi
skaner dostaje „bilet już wykorzystany”. Sprawdzenie statusu przed `UPDATE`
przepuściłoby oba.

| Kod | HTTP | Znaczenie |
|---|---:|---|
| `TICKET_TOKEN_INVALID` | 422 | kod nie jest biletem tego systemu albo podpis się nie zgadza |
| `TICKET_NOT_FOUND` | 404 | poprawny podpis, brak biletu (np. usunięty) |
| `TICKET_WRONG_SCREENING` | 409 | bilet na inny seans |
| `TICKET_CANCELLED` | 409 | bilet anulowany |
| `TICKET_ALREADY_USED` | 409 | bilet już zeskanowany |
| `TICKET_OUTSIDE_VALIDATION_WINDOW` | 409 | za wcześnie (w odpowiedzi `opens_at`) albo film się skończył (`ended_at`) |

Wszystkie kody pochodzą z jednej klasy `TicketValidationException`
z metodami fabrycznymi — skaner rozgałęzia się po `code`, a komunikat po polsku
wyświetla obsłudze.

### Rola obsługi kina

Nowa wartość `UserRole::Staff` i kolumna `users.cinema_id`. Constraint
`CHECK ((role = 'staff') = (cinema_id IS NOT NULL))` gwarantuje w bazie, że
pracownik obsługi zawsze ma kino, a klient i administrator nie mają żadnego.
Klucz obcy `restrictOnDelete` — kina z kontami obsługi nie da się usunąć
niechcący. Seeder tworzy jedno konto obsługi na kino.

### Harmonogram

| Komenda | Częstość | Co robi |
|---|---|---|
| `cinema:seat-locks:sweep` | co minutę | zwalnia wygasłe blokady (Etap 2) |
| `cinema:bookings:expire` | co minutę | wygasza nieopłacone rezerwacje (Etap 4) |
| `cinema:screenings:finish` | co 5 min | oznacza zakończone seanse jednym `UPDATE` |
| `cinema:bookings:resend-confirmations` | co 15 min | ponawia brakujące potwierdzenia |
| `cinema:screenings:send-reminders` | co 5 min | przypomnienia przed seansem |

Każde zadanie ma `withoutOverlapping()`, `onOneServer()` (zamek w Redisie —
przy dwóch replikach schedulera nic nie wykona się dwa razy),
`runInBackground()` i `appendOutputTo()`. Wyjście trafia do `/proc/1/fd/2`
kontenera, czyli do `docker compose logs scheduler`; domyślne `/dev/null`
ukrywało błędy komend (pułapka V).

**Ponawianie potwierdzeń.** Komenda bierze rezerwacje opłacone **15–120 minut
temu** bez `confirmation_sent_at` (indeks częściowy
`bookings_confirmation_pending`). Dolna granica daje zwykłej ścieżce czas na
trzy próby z backoffem; górna nie pozwala, żeby po tygodniowej awarii SMTP
klienci dostali potwierdzenia do seansów, które już się odbyły. Po dłuższej
awarii jest `--all`.

**Przypomnienia.** Rezerwacja jest **najpierw zajmowana** jednym zapytaniem
`UPDATE bookings … SET reminder_sent_at = now() FROM screenings … RETURNING id`,
a dopiero potem powiadomienie trafia do kolejki. Dwa równoległe przebiegi nie
wyślą dwóch przypomnień (stąd at-most-once). Pomijane są:

- seanse, które już się zaczęły,
- rezerwacje opłacone już po momencie przypomnienia — klient właśnie dostał
  potwierdzenie, drugi e-mail minutę później byłby spamem.

`ScreeningReminder::shouldSend()` sprawdza jeszcze raz status rezerwacji
i seansu w chwili wysyłki (seans mógł zostać odwołany, gdy zadanie czekało).

### Infrastruktura

```text
php        PHP-FPM, obsługa HTTP
worker     php artisan queue:work redis --timeout=60 --tries=3 --sleep=3 --max-time=3600
scheduler  php artisan schedule:work
mailpit    SMTP :1025, interfejs :8025
```

- `worker` i `scheduler` używają tego samego obrazu (kotwica `x-php-app`
  w `docker-compose.yml`) i działają jako `www-data` (uid 82), nie root.
- `--max-time=3600` — worker kończy się co godzinę, a Docker go wznawia;
  wycieki pamięci w długo żyjącym procesie nie narastają.
- **Worker trzyma kod w pamięci** — po zmianie kodu
  `docker compose restart worker scheduler` (pułapka N).
- Obraz PHP zawiera `zbar` i `imagemagick` wyłącznie na potrzeby testu
  dekodującego QR.

### Konfiguracja

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `QUEUE_CONNECTION` | `redis` | |
| `REDIS_QUEUE_RETRY_AFTER` | `90` | sekundy; musi być większe niż `--timeout` workera |
| `MAIL_HOST` / `MAIL_PORT` | `mailpit` / `1025` | |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `bilety@cinema.test` / `Kino - bilety` | |
| `TICKET_QR_KEY` | **brak** | klucz podpisu QR, `openssl rand -hex 32` |
| `TICKET_VALIDATION_OPENS_MINUTES` | `60` | ile minut przed seansem obsługa może skanować |
| `CONFIRMATION_RETRY_AFTER_MINUTES` | `15` | dolna granica okna ponawiania potwierdzeń |
| `CONFIRMATION_RETRY_WINDOW_MINUTES` | `120` | górna granica |
| `SCREENING_REMINDER_MINUTES` | `120` | ile minut przed seansem wysłać przypomnienie |
| `SCHEDULE_OUTPUT` | `/dev/null` | w Compose `/proc/1/fd/2` |

Parametry obrazu QR i PDF-a są w `config/tickets.php`.

### Etap 5 — decyzje projektowe (46–90)

46. **Redis jako kolejka**, nie RabbitMQ — brak nowego brokera, zamki w tym samym Redisie.
47. **Token `T1.{uuid}.{mac}`** — krótki, podpisany, wersjonowany, bez danych osobowych.
48. **dompdf** — czysty PHP, LGPL, bez przeglądarki w obrazie.
49. **endroid/qr-code** — generowanie po stronie serwera, logo, pełna kontrola ECC.
50. **Mailpit** zamiast nierozwijanego Mailhoga.
51. **Rola `staff` z `cinema_id` i policy** zamiast osobnej tabeli uprawnień.
52. **Osobny kod błędu dla każdego wyniku walidacji** — skaner pokazuje obsłudze konkretną przyczynę.
53. **Zdarzenie `BookingPaid`** oddziela płatności od biletów i poczty.
54. **Łańcuch zadanie → powiadomienie** — PDF powstaje raz, e-mail tylko go dołącza.
55. **PDF zapisany na dysku z renderem awaryjnym** — szybkie pobieranie, brak zależności od workera.
56. **`RetryPolicy` + `Queue::failing`** — jedna polityka ponowień i jeden log porażek.
57. **`reminder_sent_at` zajmowany atomowo** przed wysłaniem.
58. **Powiadomienie dopiero po capture, odbiorca idempotentny.**
59. **Worker i scheduler jako uid 82, zapisy przez atomowy `move`.**
60. **Kod biletu usunięty z URL-i i odpowiedzi API** — jedynym nośnikiem jest obraz QR.
61. **`$tries` jako właściwość, `backoff()` jako metoda** (pułapka Y).
62. **Log porażki bez komunikatu wyjątku** — komunikat może zawierać dane osobowe.
63. **Osobny `TICKET_QR_KEY`, bez wartości domyślnej, własny klucz w `phpunit.xml`.**
64. **Parametry QR**: ECC High, logo 20%, margines 10%, ISO-8859-1.
65. **Test dekoduje obraz QR**, zamiast sprawdzać nagłówek PNG.
66. **MAC 12 bajtów → QR wersji 6** bez wzorca wyrównania pod logo.
67. **Renderer PDF dostaje gotowe teksty** z presentera; szablon nie liczy stref czasowych.
68. **Jeden bilet na stronę, bez kodu jako tekstu.**
69. **Bezpieczne opcje dompdf** — bez zasobów zdalnych, JS i PHP.
70. **Znaczniki `confirmation_sent_at` / `reminder_sent_at` + indeks częściowy.**
71. **`users.cinema_id` z `restrictOnDelete`.**
72. **`BookingPaid` emitowane tylko przez `PaymentService`**, we wszystkich trzech gałęziach kończących się opłaceniem.
73. **Listener synchroniczny z `try/catch`**; webhook zawsze 2xx po wystawieniu biletów.
74. **Zadanie PDF idempotentne, unikalne i atomowe.**
75. **Powiadomienie w kolejce z `shouldSend()`**, gwarancja at-least-once.
76. **`BookingTicketsPresenter` wspólny dla PDF-a i e-maila.**
77. **Katalog `tickets` z uprawnieniami dla wszystkich** — PHP-FPM i worker mają różne uid (pułapka AB).
78. **Token Sanctum zamiast podpisanego URL-a** do pobierania biletów.
79. **Bilet w URL-u ograniczony do rezerwacji (`scopeBindings`)**, klucz trasy = `id`.
80. **Kolejność walidacji od najtańszej + atomowy `UPDATE`** rozstrzygający wyścig skanerów.
81. **Jedna klasa wyjątku walidacji z metodami fabrycznymi.**
82. **Limitery per użytkownik** dla pobierania i walidacji.
83. **`qr_url` w `TicketResource` zamiast kodu.**
84. **Wyjście harmonogramu do logów kontenera.**
85. **`onOneServer()`** na wszystkich zadaniach harmonogramu.
86. **Kończenie seansów jednym `UPDATE`**, bez ładowania modeli.
87. **Ponawianie potwierdzeń w oknie 15–120 min, co 15 min.**
88. **Commit po każdym bloku pracy** — łatwy powrót zamiast kopii w `/tmp`.
89. **Przypomnienia at-most-once**, z oknem czasowym i pomijaniem spóźnionych płatności.
90. **Przypomnienie bez PDF-a.**

### Etap 5 — pułapki, na które trafiliśmy (N–AH)

- **N. Worker trzyma kod w pamięci.** Zmiana w klasie zadania nie działa, dopóki
  nie zrestartuje się kontenera `worker`.
- **O. `retry_after` musi być większe niż `--timeout`.** Inaczej Redis oddaje
  trwające zadanie drugiemu procesowi i wykonuje się ono równolegle dwa razy.
- **P. Pliki tworzone przez `docker compose exec` należą do roota.** Katalogi
  zakładamy w WSL jako zwykły użytkownik.
- **Q. W `phpunit.xml` kolejka jest `sync`.** `dispatch()` wykonuje zadanie od
  razu, w środku testowanej akcji; testy przepływu używają `Queue::fake()`.
- **R. `preventLazyLoading()` działa też w workerze.** Powiadomienie sięgające
  po niezaładowaną relację rzuca wyjątek dopiero w tle — relacje ładujemy jawnie.
- **S. Reguła nginx dla plików statycznych** — sprawdzone, nie dotyczy: ścieżki
  `/tickets/pdf` i `/qr` nie mają rozszerzeń, więc trafiają do PHP.
- **T. Czcionki dompdf.** Wbudowane czcionki nie mają polskich znaków, a katalog
  cache czcionek musi być zapisywalny dla workera.
- **U. `schedule:list` pokazuje zadania, ale ich nie uruchamia.** Potrzebny jest
  `schedule:work` (albo cron z `schedule:run`).
- **V. Wyjście schedulera domyślnie idzie do `/dev/null`.** Błąd komendy był
  niewidoczny, dopóki wyjście nie trafiło do logów kontenera.
- **W. Pływający tag obrazu bazowego.** Przebudowa pobrała nowszy obraz PHP
  i trwała prawie 4 minuty zamiast kilku sekund.
- **X. Domknięcia z `tinker --execute` nie da się zserializować** do kolejki
  (kod z `eval`). Do testów workera powstała klasa `QueueProbeJob`.
- **Y. Powiadomienia i mailable ignorują metodę `tries()`.** Liczbę prób trzeba
  podać właściwością `$tries`.
- **Z. Nieistniejący pakiet.** Dekoder QR proponowany jako zależność PHP nie
  jest na Packagist — sprawdzać przed `composer require`. Zastąpił go `zbarimg`.
- **Z2. `zbarimg` bez `imagemagick` nie czyta PNG** (`NoDecodeDelegate`).
- **Z3. Logo zasłania środkowy wzorzec wyrównania QR wersji 7.** Rozwiązanie:
  krótszy token i wersja 6 (decyzja 66).
- **AA. Błąd wewnątrz `$(...)` nie przerywa łańcucha `&&`.** Pusty wynik
  `cat` szedł dalej jako pusty skrypt; pomaga `test -s plik &&`.
- **AB. Flysystem zapisuje „prywatne” pliki z prawami 0700/0600.** Plik
  utworzony przez worker (uid 82) był nieczytelny dla innego procesu.
- **AC. Dysk z `throw => false` zwraca `false` zamiast rzucać wyjątek.**
  Wynik `put()` / `move()` trzeba sprawdzać jawnie.
- **AD. `PendingDispatch` wysyła zadanie w destruktorze.** Wyjątek kolejki
  wylatywał poza `try`; pomaga `unset()` wewnątrz bloku `try`.
- **AE. `ShouldBeUnique` zakłada zamek także przy `Queue::fake()`.** Drugi
  dispatch w tym samym teście był po cichu pomijany.
- **AF. `/tmp` w WSL znika po restarcie.** Kopie zapasowe zastępuje `git diff`.
- **AG. `git diff` nie pokazuje plików nieśledzonych** — do tego `git status`.
- **AH. PDO pgsql nie przyjmuje dwa razy tego samego parametru nazwanego.**
  W surowym `UPDATE … RETURNING` użyte są parametry pozycyjne `?`.

### Etap 5 — testy

| Klasa testu | Liczba | Obszar |
|---|---:|---|
| `RetryPolicyTest` (Unit) | 4 | liczba prób, wykładnicze opóźnienia, granice rozrzutu |
| `TicketTokenSignerTest` (Unit) | 14 | format, determinizm, podmiana UUID, inny klucz, zmieniona wersja, zniekształcone tokeny (data provider), brak klucza |
| `TicketQrRendererTest` | 4 | **dekodowanie obrazu z logo przez `zbarimg`**, kwadratowy PNG, data URI, brak pliku logo |
| `TicketPdfRendererTest` | 5 | strefa czasowa kina, miejsca i QR w kolejności, brak kodu jako tekstu, strona na bilet, nieopłacona rezerwacja |
| `StaffCinemaConstraintTest` | 3 | constraint roli i kina w bazie |
| `BookingConfirmationFlowTest` | 6 | `BookingPaid` dopiero po capture, brak drugiego ogłoszenia, ponowienie po awarii capture, BLIK, nieudana płatność, utrata miejsc |
| `GenerateBookingTicketsPdfTest` | 6 | zapis PDF i zlecenie e-maila, pominięcie nieopłaconej i już potwierdzonej, załącznik bez kodów, znacznik blokujący ponowną wysyłkę, polityka ponowień |
| `TicketDownloadTest` | 7 | PDF i QR właściciela, cudzy PDF, brak logowania, nieopłacona, bilet z innej rezerwacji → 404, `qr_url` zamiast kodu |
| `TicketValidationTest` | 10 | wpuszczenie, drugi skan, inny seans, podrobiony kod, anulowany, przed otwarciem wejścia, obsługa innego kina, klient, administrator, brak logowania |
| `FinishScreeningsCommandTest` | 2 | tylko zaplanowane seanse po końcu filmu, ponowne uruchomienie |
| `ResendBookingConfirmationsCommandTest` | 2 | okno ponawiania, `--all` |
| `SendScreeningRemindersCommandTest` | 4 | tylko opłacone w oknie, brak ponownej wysyłki, godzina w strefie kina bez kodu, anulowanie przed wysyłką |
| Etapy 1–4 | 74 | (w tym `StripeWebhookTest` i `PaymentRaceTest` z `Queue::fake()`) |
| **Razem** | **141** | |

### Etap 5 — weryfikacja na żywo

Poza testami automatycznymi sprawdzone ręcznie na działającym środowisku:

- płatność testowa przez Stripe CLI → webhook → bilety → capture;
- zadanie celowo padające: trzy próby z opóźnieniami ok. 10 s i 40 s, wpis
  w `failed_jobs` i w logu workera;
- kod QR odczytany przez `zbarimg` i aparat telefonu;
- PDF: `pdftotext` (polskie znaki), `pdffonts` (osadzona DejaVu Sans);
- e-mail z załącznikiem w Mailpit; ponowny dispatch nie wysłał duplikatu;
- pobranie PDF-a i QR przez `curl` z tokenem właściciela i odmowa dla innego
  użytkownika;
- dwa równoległe skany tego samego biletu: jeden sukces, jeden
  „już wykorzystany”;
- logi komend harmonogramu w `docker compose logs scheduler`;
- odzyskanie potwierdzenia komendą ponawiającą po zatrzymanym workerze.

### Etap 5 — znane ograniczenia i co dalej

- **`capture` nadal synchronicznie w webhooku** (zapowiedź z Etapu 4). Świadomie:
  capture musi nastąpić tuż po wystawieniu biletów, a ponowienia zapewnia sam
  Stripe. Przeniesienie do kolejki dodałoby stan „bilety są, pieniędzy jeszcze
  nie” bez realnego zysku przy czasie capture rzędu kilkuset milisekund.
- **Duplikat potwierdzenia jest możliwy** (at-least-once), przypomnienie może
  nie dojść (at-most-once) — patrz tabela gwarancji.
- **Brak zgody klienta na przypomnienia** — dziś dostaje je każdy. Docelowo
  preferencja w profilu; push (FCM) dojdzie z aplikacją mobilną.
- **Weryfikacja kodu tylko online.** Skanowanie offline wymagałoby tokenu `T2`
  z podpisem asymetrycznym i synchronizacji listy wykorzystanych biletów.
- **Brak ręcznego wpisania kodu** przy uszkodzonym ekranie — obsługa może
  wyszukać rezerwację po numerze w panelu (Etap 7).
- **Pracownik obsługi przypisany do jednego kina.** Obsługa kilku kin
  wymagałaby tabeli łączącej.
- **PDF-y na dysku lokalnym.** Przy kilku replikach PHP potrzebny jest S3 lub
  inny wspólny dysk; `TicketPdfStore` jest jedynym miejscem do zmiany.
- **Jeden Redis dla cache i kolejki, bez limitu `maxmemory`.** Dziś rośnie do
  granic pamięci hosta. Docelowo osobne instancje: cache z limitem i `allkeys-lru`,
  kolejka z `noeviction`, żeby wypychanie kluczy nigdy nie usunęło zadania.
- **`zbar` i `imagemagick` w obrazie produkcyjnym** — do usunięcia przez
  wieloetapowy Dockerfile (Etap 10), razem z przypięciem obrazu bazowego do
  konkretnej wersji.
- **Migracje i restart workera nie są automatyczne** — trafią do entrypointu
  w Etapie 10.

---

## Etap 6 — WebSocket (Laravel Reverb)

Plan sali zmienia się na żywo u wszystkich oglądających, właściciel rezerwacji
dowiaduje się o wyniku płatności bez odpytywania API, a panel dostaje feed
sprzedaży. Serwer WebSocket to **Laravel Reverb** (protokół Pushera), więc
klienci używają gotowych bibliotek: `laravel-echo` + `pusher-js` w Vue
i klienta Pushera we Flutterze.

Zasada przewodnia całego etapu: **broadcast to powiadomienie, a nie warunek
sprzedaży**. Blokada miejsca, płatność i bilety są zatwierdzone w bazie, zanim
cokolwiek wyjdzie do Reverba. Awaria Reverba kończy się ostrzeżeniem w logu,
nigdy błędem operacji.

### Architektura

```text
przeglądarka / telefon                       kontenery PHP (php, worker, scheduler)
  │                                             │
  │ ws://localhost:8080/app/{REVERB_APP_KEY}    │ POST http://reverb:8080/apps/{id}/events
  ▼                                             ▼   (podpis sekretem aplikacji)
nginx ── location /app/ ──────────────────► reverb (php artisan reverb:start)
  │
  └── /api/v1/broadcasting/auth ──► PHP-FPM: ChannelAuthorizationService → Policies
```

- Reverb nie ma portów na hoście. Klienci wchodzą przez nginx ścieżką `/app/`,
  a API publikacji (`/apps/...`) jest dostępne wyłącznie w sieci Dockera.
- Laravel publikuje zdarzenie **zwykłym żądaniem HTTP** (`pusher/pusher-php-server`
  na Guzzle). Reverb rozsyła je subskrybentom kanału.

### Kanały i zdarzenia

| Kanał | Kto może subskrybować | Zdarzenia |
|---|---|---|
| `private-screenings.{id}` | każdy, także anonim — jeśli seans jest w sprzedaży i jeszcze się nie zaczął (`ScreeningPolicy::watchSeatMap`) | `seats.changed`, `seats.resync` |
| `private-bookings.{reference}` | **tylko właściciel** rezerwacji (`BookingPolicy::listen`) | `booking.status-changed` |
| `private-cinemas.{id}.sales` | administrator i obsługa **tego** kina (`CinemaPolicy::viewSales`) | `sales.activity` |
| `private-sales` | tylko administrator (`CinemaPolicy::viewAnySales`) | `sales.activity` |

Payloady (pełne, bez skrótów):

```json
// seats.changed — stan ABSOLUTNY zmienionych miejsc, pogrupowany po statusie
{"screening_id": 53, "version": 7, "seats": {"held": [311, 312]}}

// seats.resync — zmiana za duża na jedno zdarzenie; klient pobiera plan sali
{"screening_id": 53, "version": 8}

// booking.status-changed
{"reference": "01M2…", "status": "paid", "status_label": "Opłacona",
 "occurred_at": "2026-09-15T20:01:52+00:00"}

// sales.activity — typ: booking.created | paid | cancelled | expired | refunded
{"type": "booking.paid", "reference": "01M2…", "status": "paid", "status_label": "Opłacona",
 "cinema": {"id": 1, "name": "Kino Atlantyk"},
 "screening": {"id": 51, "starts_at": "2026-09-15T16:45:00+02:00", "movie_title": "Incepcja", "hall_name": "Sala 1"},
 "seats_count": 2, "total": {"amount": 4400, "currency": "PLN", "formatted": "44,00 zł"},
 "occurred_at": "2026-09-15T20:01:52+00:00"}
```

**Żadnych danych osobowych** (wymóg 1.3): ani sesji zakupowej, ani e-maila,
imienia czy `user_id`. Broadcast mówi, *które* miejsce jest zajęte, a nie
*czyje*; feed mówi, *co* sprzedano, a nie *komu*. Testy pilnują białej listy
kluczy, więc nowe pole w payloadzie musi być świadomą decyzją.

Kiedy wychodzą zdarzenia rezerwacji:

| Moment | Kanał właściciela | Feed sprzedaży |
|---|---|---|
| `BookingService::checkout()` — **nowa** rezerwacja | — (klient zna wynik z odpowiedzi) | `booking.created` |
| `BookingPaid` — dopiero po capture (decyzja 72) | `paid` | `booking.paid` |
| wygaśnięcie / anulowanie / zwrot | status | `booking.expired` / `.cancelled` / `.refunded` |
| wycofanie biletów | `cancelled` | `booking.cancelled` |

### Autoryzacja kanałów

`POST /api/v1/broadcasting/auth` — własny endpoint zamiast `Broadcast::routes()`.
Standardowa ścieżka odrzuca kanał `private-*` kodem 403, zanim zapyta callback
kanału, jeśli żądanie nie ma zalogowanego użytkownika — a plan sali ma działać
także dla kupującego bez konta.

- Token bearer jest **opcjonalny**; użytkownika czytamy strażnikiem `sanctum`.
  O dostępie anonima decyduje Policy, nie middleware.
- `ChannelAuthorizationService` ma jawną mapę *nazwa kanału → zasób → Policy*.
  Nieznany kanał to odmowa — nie ma „domyślnie wpuść”.
- Podpis HMAC liczy ta sama biblioteka, której używa framework
  (`getPusher()->authorizeChannel()`), więc serwis nie dotyka obiektu `Request`.
- Sukces to surowe `{"auth": "klucz:podpis"}` bez koperty `data` — tego wymaga
  protokół Pushera. Błędy mają zwykły kształt z polem `code`.

| Kod | HTTP | Kiedy |
|---|---|---|
| `CHANNEL_FORBIDDEN` | 403 | brak uprawnień, nieznany kanał **albo nieistniejący zasób** (bez enumeracji rezerwacji i seansów) |
| `REALTIME_UNAVAILABLE` | 503 | skonfigurowany broadcaster nie potrafi podpisywać (błąd konfiguracji, logowany) |
| `VALIDATION_FAILED` | 422 | zły `socket_id` albo kanał spoza `private-*` |
| `TOO_MANY_REQUESTS` | 429 | limiter `broadcasting-auth`: 60/min per klient (użytkownik, sesja zakupowa albo IP) **i** 1200/min per IP |

### Kolejność zdarzeń i reconnect: wersja stanu miejsc

Tabela `screening_seat_versions` trzyma licznik per seans. Każda transakcja,
która zmienia stan miejsc (blokada, zwolnienie, sweep, bilety, wygaśnięcie,
wycofanie), jako **ostatnią instrukcję** wykonuje:

```sql
INSERT INTO screening_seat_versions (screening_id, version, updated_at) VALUES (?, 1, now())
ON CONFLICT (screening_id) DO UPDATE SET version = screening_seat_versions.version + 1, updated_at = now()
RETURNING version
```

- Upsert blokuje wiersz licznika do COMMIT, więc **numer wersji odpowiada
  kolejności commitów**. Transakcje, które przegrały wyścig o miejsce (409),
  do licznika w ogóle nie dochodzą.
- Plan sali (`GET /screenings/{id}/seat-map`) zwraca `seat_state_version`.
  Wersję czytamy **przed** stanem miejsc, więc jest dolną granicą: stan może
  zawierać zmiany nowsze niż wersja, nigdy starsze. Nie trzeba `REPEATABLE READ`.

**Algorytm klienta** (wymóg 1.3: „po utracie połączenia pełny stan przez REST,
potem subskrypcja”):

1. `GET seat-map` → stan i wersja `V`.
2. Subskrypcja `private-screenings.{id}`.
3. Po `subscription_succeeded` ponowny odczyt wersji. Jeśli jest większa niż
   `V`, zmiana wpadła w okno między snapshotem a subskrypcją — klient pobiera
   plan jeszcze raz. **Kolejność z wymogu ma to okno; licznik je zamyka.**
4. Zdarzenia z `version <= znana` klient pomija; zdarzenie z `version > znana + 1`
   oznacza lukę → `GET seat-map`. `seats.resync` → `GET seat-map`.

Zdarzenia niosą stan absolutny, więc ponowne zastosowanie jest nieszkodliwe.

Działająca konfiguracja klienta `pusher-js` (host, port, własny handler
autoryzacji) jest w `tools/realtime-probe/probe.mjs`, funkcja `connect()`.
W `laravel-echo` nazwy zdarzeń z `broadcastAs()` podaje się z kropką na
początku (`.seats.changed`), inaczej Echo dokleja przestrzeń nazw `App\Events`.

### Wysyłka po COMMIT, odporność i bezpiecznik

```text
DB::transaction
  ├─ … zmiana stanu miejsc / rezerwacji …
  ├─ SeatStateRecorder::record() → wersja N → DB::afterCommit(seatsChanged)
  └─ BookingService → DB::afterCommit(bookingChanged)
COMMIT ──────────────────────────────────────────────────────────────────
  └─ RealtimeNotifier
       ├─ bezpiecznik otwarty?  → pomiń (0 ms, bez logu)
       ├─ event(ShouldBroadcastNow) → Reverb (connect_timeout 0,5 s)
       └─ wyjątek → otwórz bezpiecznik na 10 s + JEDNO ostrzeżenie w logu
```

- **Po ROLLBACK zdarzenie nie wychodzi** — callback `afterCommit` przepada,
  więc klient nigdy nie zobaczy „ducha” zmiany, której nie ma w bazie.
  Żądanie HTTP do Reverba nie trzyma też blokad wierszy.
- **`ShouldBroadcastNow`, bez kolejki.** Spóźnione o 10–40 s zdarzenie (ponowienie
  z kolejki) niosłoby stan już nieaktualny.
- **Krótkie timeouty klienta publikacji** (`client_options`): framework domyślnie
  czeka 10 s na połączenie i 30 s na odpowiedź. Zmierzone przy niedostępnym
  hoście: 0,51 s zamiast 10,00 s.
- **Bezpiecznik** (`RealtimeCircuitBreaker`): klucz w cache (Redis) z czasem życia.
  Dopóki istnieje, żaden proces — php-fpm, worker, scheduler — nie próbuje
  wysyłać. `Cache::add()` to w Redisie `SET NX`, więc z kilku procesów, które
  zawiodły jednocześnie, dokładnie jeden zapisuje ostrzeżenie. Awaria samego
  cache nie blokuje wysyłki (*fail-open*).

Koszt awarii Reverba przed i po bezpieczniku:

| Scenariusz | Bez bezpiecznika | Z bezpiecznikiem |
|---|---|---|
| blokada miejsca | +0,5 s każda | +0,5 s pierwsza, potem ~0 |
| przejście rezerwacji (miejsca, właściciel, feed) | +1,5 s każde | ~0 przy otwartym |
| przebieg wygaszania 100 rezerwacji | do ~150 s | ~0,5 s |

### Infrastruktura

- Kontener **`reverb`** z tej samej kotwicy `x-php-app` co `php`, `worker`
  i `scheduler`: `php artisan reverb:start`, uid 82, healthcheck `GET /up`,
  bez sekcji `ports`.
- **nginx** `location ^~ /app/` z nagłówkami `Upgrade` / `Connection`
  i `proxy_read_timeout 120s`. Adres Reverba idzie przez zmienną
  i `resolver 127.0.0.11`, dzięki czemu nginx startuje i obsługuje API także
  wtedy, gdy kontenera `reverb` nie ma (502 wyłącznie na `/app/`).
- `laravel/reverb` 1.x instalowany ręcznie: `config/broadcasting.php` zawiera
  tylko połączenia `reverb`, `log` i `null`; `config/reverb.php` jest
  opublikowany bez zmian.
- **Reverb trzyma konfigurację w pamięci** — po zmianie `REVERB_*`:
  `docker compose restart reverb`.

### Konfiguracja

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `BROADCAST_CONNECTION` | `reverb` | w `phpunit.xml`: `null` |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | **brak** | identyfikator, klucz publiczny (trafia do klientów) i sekret podpisu |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `reverb` / `8080` / `http` | adres Reverba widziany **z kontenerów PHP** |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | `0.0.0.0` / `8080` | na czym nasłuchuje sam Reverb |
| `REVERB_CLIENT_CONNECT_TIMEOUT` / `REVERB_CLIENT_TIMEOUT` | `0.5` / `1.5` | sekundy; limit publikacji |
| `BROADCAST_MAX_PAYLOAD_BYTES` | `8000` | większa zmiana idzie jako `seats.resync` (Reverb przyjmuje do 10 000 bajtów) |
| `BROADCAST_BREAKER_SECONDS` | `10` | czas wstrzymania wysyłki po porażce; `0` wyłącza bezpiecznik |

### Etap 6 — decyzje projektowe (91–127)

91. **Ręczna instalacja Reverba** zamiast `install:broadcasting` / `reverb:install` —
    kontrola nad każdym plikiem; instalatory dopisują trasy kanałów i zależności frontu.
92. **Celowana aktualizacja zależności**: Guzzle 7 zamiast przeskoku całego drzewa
    (3 pakiety w dół zamiast 34 zmian, framework bez zmiany wersji).
93. **Krótkie timeouty klienta publikacji**: 0,5 s na połączenie, 1,5 s łącznie.
94. **Rozdzielone adresy Reverba**: publikacja z kontenerów na `reverb:8080`,
    klienci przez nginx `/app/`; osobne zmienne dla nasłuchu serwera.
95. **Reverb za nginx, wystawione tylko `/app/`**; HTTP API publikacji niedostępne z zewnątrz.
96. **`resolver` + zmienna w `proxy_pass`** — brak kontenera `reverb` nie wyłącza API.
97. **Kontener `reverb` z kotwicy `x-php-app`**, uid 82, healthcheck `GET /up`, bez portów na hosta.
98. **Własny endpoint autoryzacji** zamiast `Broadcast::routes()` — anonim na kanale seansu.
99. **Mapa kanał → zasób → Policy, domyślnie odmowa**; podpis przez `authorizeChannel()`.
100. **403 także dla nieistniejącego zasobu** — endpoint nie zdradza, które rezerwacje istnieją.
101. **Surowe `{"auth"}`** jako jedyny świadomy wyjątek od koperty `data`.
102. **`BookingPolicy::listen` węższe niż `view`** — administrator ma feed, nie podsłuch klienta.
103. **Feed kina dla obsługi tego kina, feed sieci tylko dla administratora.**
104. **503 `REALTIME_UNAVAILABLE`** dla broadcastera bez podpisów; odmowa pozostaje 403.
105. **Limiter o dwóch progach** (60/min per klient, 1200/min per IP) — przetrwa burzę
    reconnectów i salę za jednym NAT-em.
106. **Licznik wersji per seans** podbijany upsertem jako ostatnia instrukcja transakcji.
107. **Wersja w snapshocie jako dolna granica** — odczyt przed stanem miejsc.
108. **`SELECT … FOR UPDATE` + `UPDATE` po `id`** — wersja obejmuje wyłącznie miejsca
    zmienione w tej transakcji (sweep mógł część zwolnić wcześniej).
109. **Wysyłka przez `DB::afterCommit` z jednego miejsca** (`SeatStateRecorder`) —
    serwisy nie znają Reverba.
110. **`ShouldBroadcastNow` bez kolejki.**
111. **Awaria Reverba = ostrzeżenie w logu** (`RealtimeNotifier`), bez treści wyjątku (jak 62).
112. **Stan absolutny, posortowany, bez danych osobowych**; jawne `broadcastWith()`,
    bo bez niego Laravel wysłałby publiczne właściwości zdarzenia.
113. **`seats.resync` zamiast dzielenia payloadu** — jedna wersja = jedno zdarzenie.
114. **`event()` zamiast wstrzykniętego dispatchera** — `Event::fake()` działa w testach
    niezależnie od chwili zbudowania serwisu.
115. **„paid” ogłasza słuchacz `BookingPaid`**, nie `fulfil()` — bez sprzedaży, której nie było.
116. **Status przejścia podaje wywołujący**, nie odczyt z bazy po COMMIT.
117. **`booking.created` tylko do feedu** — nikt nie może jeszcze słuchać kanału rezerwacji.
118. **Jedno zdarzenie feedu na dwa kanały** — jedno żądanie do Reverba.
119. **Pola jak w REST** (`total`, `status_label`) i biała lista kluczy w testach.
120. **`seats_count` z `seat_locks`** — są przypięte do rezerwacji przy każdym statusie.
121. **Bez `tickets_ready`** — pobranie PDF-a generuje brakujący plik, a flaga
    wprowadzałaby problem kolejności zdarzeń.
122. **Bezpiecznik w cache z TTL**, `add()` = jeden log, *fail-open*, `BROADCAST_BREAKER_SECONDS`.
123. **Sonda WebSocket w repozytorium** (`tools/realtime-probe`), `pusher-js` 8.6.0
    przypięty z `package-lock.json`.
124. **Node w kontenerze z UID użytkownika**; tokeny sondy w pliku `0600`, usuwane po teście.
125. **Reconnect: REST → subskrypcja → ponowny odczyt wersji**; luka w numeracji → snapshot.
126. **`allowed_origins` = `*`** — klienci mobilni i serwerowi nie wysyłają `Origin`,
    a Reverb z listą odrzuca brak nagłówka; dane chronią podpisy kanałów i Policies.
127. **`starts_at` w strefie kina, `occurred_at` w strefie aplikacji** — oba ISO 8601 z offsetem.

### Etap 6 — pułapki, na które trafiliśmy (AI–AV)

- **AI. Guzzle 8 kontra `guzzlehttp/psr7` 2.x.** `composer require laravel/reverb`
  kończył się kodem 2; `-W` zmieniłby 34 pakiety razem z frameworkiem.
  Rozwiązanie: `require --no-update`, potem `update` czterech wskazanych pakietów.
- **AJ. Domyślne timeouty publikacji to 10 s i 30 s.** Przy niedziałającym
  Reverbie blokada miejsca wisiałaby 10 sekund.
- **AK. `Broadcast::routes()` odrzuca gościa przed callbackiem kanału** — kanał
  seansu dla anonima wymagał własnego endpointu.
- **AL. `NullBroadcaster` niczego nie sprawdza.** Test „odmowy” na
  `BROADCAST_CONNECTION=null` jest fałszywie zielony — testy autoryzacji
  przełączają się na broadcaster podpisujący z testowym kluczem.
- **AM. `BroadcastManager` pamięta utworzone połączenia.** Zmiana configu
  w teście nie działa bez `forgetDrivers()`.
- **AN. Statyczny `proxy_pass http://reverb:8080`** — gdy kontenera nie ma,
  nginx nie startuje wcale i pada całe API.
- **AO. `sed -i` na pliku zamontowanym pojedynczo.** `sed` tworzy nowy plik
  (nowy i-węzeł), a kontener dalej widzi stary.
- **AP. Reverb odrzuca połączenie bez nagłówka `Origin`,** gdy `allowed_origins`
  nie jest `*`. `pusher-js` w Node i klienci mobilni tego nagłówka nie wysyłają.
- **AQ. `PusherBroadcaster` opakowuje `Pusher\ApiErrorException`
  w `BroadcastException`** — klasa wyjątku zależy od tego, czy wołamy klienta
  Pushera wprost, czy przez broadcaster.
- **AR. Blok skopiowany bez pierwszej linii** (`cd ~/cinema && {`) — polecenia
  wykonały się pojedynczo, a `cd` zmienił katalog powłoki.
- **AS. Nowe pliki gotowe, łatka niezastosowana.** Testy padły na „zdarzenie
  wysłane 0 razy”, a `wc -l` nowych plików się zgadzało. Przed testami:
  `git status` musi pokazać `M` przy łatanych plikach.
- **AT. Raport nadpisywany `>`, commit dopisywany `>>`.** Plik z samym wynikiem
  commitu wyglądał na „zrobione”, choć pełnego zestawu nie uruchomiono.
  Komenda commitu sprawdza teraz w raporcie `EXIT całość: 0`.
- **AU. `pusher-js` 8 wymaga opcji `cluster`** nawet przy własnym `wsHost`.
- **AV. Po restarcie Dockera / WSL** pełny zestaw kończy się `EXIT 1` bez linii
  `Tests:`, a tinker wypisuje `Could not open input file: artisan` — nieaktualne
  montowanie katalogu, pomaga `cinema-up`.

### Etap 6 — testy

| Klasa testu | Liczba | Obszar |
|---|---:|---|
| `BroadcastingAuthTest` | 22 | kanał seansu (anonim, klient, odwołany, rozpoczęty, nieistniejący), kanał rezerwacji (właściciel przez token bearer, inny klient, administrator, anonim, nieistniejąca → 403), feed kina i sieci (obsługa swojego i innego kina, administrator, klient, anonim), nieznany kanał, walidacja, 503 bez podpisów, limiter |
| `SeatStateVersionTest` | 14 | wersja raz na operację, retry i 409 bez wersji, zwolnienia, sweep per seans, bilety, wygaśnięcie (także po sweepie), wycofanie, `seat_state_version` w planie sali |
| `SeatLockConcurrencyTest` | (3) | rozszerzony o wersję: 20 procesów na jedno miejsce → wersja 1, 10 różnych miejsc → 10 |
| `SeatEventsBroadcastTest` | 12 | jedno zdarzenie na zmianę z tą samą wersją, po COMMIT i nigdy po ROLLBACK, zwolnienia i sweep, bilety, payload bez danych osobowych, `seats.resync`, prawdziwy broadcaster pod martwym adresem |
| `BookingEventsBroadcastTest` | 13 | pełny payload feedu, podwójny checkout, ROLLBACK, przejścia (data provider), „paid” dopiero po capture, wycofanie biletów, biała lista kluczy, **nazwy kanałów ze zdarzeń przepuszczone przez prawdziwą autoryzację**, awaria Reverba |
| `RealtimeCircuitBreakerTest` | 6 | broadcaster liczący próby: otwarcie, wygaśnięcie (`travel`), sukcesy, `0` wyłącza, checkout bez zapytania przy otwartym, awaria cache |
| Etapy 1–5 | 141 | |
| **Razem** | **208** | |

### Etap 6 — weryfikacja na żywo

- Handshake WebSocket `101` przez nginx `/app/`; `/apps/...` z zewnątrz → 404;
  zatrzymany `reverb` → 502 wyłącznie na `/app/`, API działa.
- Publikacja do niedostępnego hosta: 0,51 s zamiast 10,00 s.
- Podpis kanału z endpointu porównany z niezależnie policzonym HMAC.
- Blokada przez API przy zatrzymanym Reverbie: `201` w 0,55 s, ostrzeżenie w logu
  (przed bezpiecznikiem).
- Zdarzenia rezerwacji: publikacja na dwa kanały w 0,05 s, payload 385 bajtów.
- Bezpiecznik: trzy cykle blokada/zwolnienie przy zatrzymanym Reverbie — pierwsza
  blokada 0,70 s, kolejne operacje 0,04–0,06 s, klucz w Redisie z TTL 8 s,
  **jedno** ostrzeżenie; po wygaśnięciu klucza wysyłka wraca, zero ostrzeżeń.
- **Sonda `tools/realtime-probe`** (`pusher-js` w kontenerze Node, przez nginx):
  **15/15 PASS** — dwóch anonimowych subskrybentów dostaje „held” i „free”
  z tą samą wersją po blokadzie `curl`-em, klient traci połączenie i wraca
  według algorytmu z wersją, właściciel dostaje status rezerwacji, administrator
  identyczny wpis feedu na obu kanałach, a anonim i obcy klient dostają
  `403 CHANNEL_FORBIDDEN` na cudzej rezerwacji i feedach.

Sonda potrzebuje danych i tokenów przygotowanych w tinkerze; skrypt
uruchamiający ją jednym poleceniem trafi do Etapu 10 (CI).

### Etap 6 — znane ograniczenia i co dalej

- **Kanał seansu jest „prywatny” jako bramka, a nie tajemnica.** Subskrybować
  może każdy, kto ogląda seans w sprzedaży; chroni go brak danych osobowych
  w payloadzie, a nie podpis.
- **Jedna instancja Reverba.** Skalowanie poziome wymaga włączenia skalowania
  Reverba przez Redis pub/sub oraz load balancera przepuszczającego WebSocket.
- **Brak `wss://` w środowisku deweloperskim** — TLS na nginx w Etapie 10.
- **Po awarii Reverba wysyłka wraca najpóźniej po 10 s** (bezpiecznik). Klienci
  wykrywają lukę po numerze wersji albo odświeżają stan przy reconnect.
- **Teoretyczny deadlock** między `fulfil()` (blokady rezerwacji w kolejności
  `seat_id`) a sweepem i `finish()` (kolejność `id`). PostgreSQL wykrywa go
  i przerywa jedną transakcję: webhook Stripe'a zostanie ponowiony, sweep
  wykona się w następnym przebiegu. Docelowo jedna kolejność blokad wszędzie.
  **Rozwiązane w Etapie 7** (decyzje 166–167).
- **`payment_intent.payment_failed` nie jest rozgłaszane** — rezerwacja zostaje
  `pending` (klient może poprawić dane karty w oknie płatności), a błąd karty
  zna od razu ze Stripe.js.
- **`occurred_at` w feedzie to chwila wysyłki**, nie znacznik z bazy. Feed jest
  powiadomieniem; źródłem prawdy będzie lista rezerwacji w panelu (Etap 7).
- **Sonda wywołuje zdarzenia rezerwacji próbną wysyłką**, a nie prawdziwym
  checkoutem (ten tworzyłby płatność w Stripe) — przejścia pokrywają testy.
- **`.env.example` ma `CACHE_STORE=database`**, a środowisko używa Redisa;
  bez `APP_NAME` klucze w Redisie mają prefiks `laravel-…`. Porządek w Etapie 10.

---

## Etap 7 — panel administracyjny (Livewire), cache w Redisie, moduł informacyjny

Panel pod <http://localhost:8080/admin> obsługuje całą część administracyjną zadania:
strukturę kin i sal z edytorem układu, filmy z plakatami, repertuar z walidacją
kolizji i kopiowaniem dni, sprzedaż (pulpit, lista rezerwacji, plan sali, feed na
żywo, anulowanie ze zwrotem) oraz artykuły. Katalog w publicznym API jest
cachowany w Redisie z inwalidacją przy każdej zmianie w panelu.

Zasada przewodnia: **komponent Livewire tylko zbiera dane i woła serwis**.
Każda reguła zależna od stanu bazy (kolizje, sprzedaż, nadchodzące seanse)
zapada w serwisie pod blokadą wiersza, a komponent pokazuje komunikat
z wyjątku domenowego.

### Panel: dostęp i role

| Rola | Co widzi i może |
|---|---|
| administrator | wszystko: kina, sale, układy, filmy, repertuar, rezerwacje z pełnym e-mailem klienta, anulowanie, artykuły, pulpit sieci |
| obsługa kina | tylko **swoje kino** i tylko odczyt: pulpit, lista rezerwacji (e-mail zamaskowany), plan sali, siatka repertuaru |
| klient, gość | brak dostępu (formularz logowania, 403 dla zalogowanego klienta) |

- **Logowanie sesją** (guard `web`), a nie tokenem — panel to strony renderowane
  przez serwer, ciasteczko HttpOnly. API zostaje przy tokenach Sanctum.
  `PanelAuthService` daje jeden komunikat dla złego hasła, nieistniejącego konta
  i konta klienta; limit liczy **wyłącznie porażki** (5/min konto+IP, 20/min IP),
  udane logowanie zeruje licznik konta. Po zalogowaniu `session()->regenerate()`,
  przy wylogowaniu `invalidate()` + `regenerateToken()`.
- **Gate `panel.access`** (`AdminPanelServiceProvider`) to bramka grupy `/admin`.
  O konkretnym rekordzie decydują Policies: `CinemaPolicy`, `HallPolicy`,
  `MoviePolicy`, `ScreeningPolicy`, `BookingPolicy`, `ArticlePolicy`.
- **Każda publiczna metoda komponentu to endpoint.** `authorize()` w `mount()`
  i w każdej akcji, identyfikatory z `#[Locked]`, rekord ładowany od nowa w akcji.
  Zakres obsługi kina liczony na serwerze przy każdym renderze — podmieniony
  `?kino=` w adresie niczego nie odsłania.
- **Sesje w Redisie w DB 2** (połączenie `session`): DB 0 to kolejka i muteksy
  harmonogramu, DB 1 cache. `cache:clear` nie wylogowuje administratorów.

Trasy panelu (`routes/web.php`): `/admin/login`, `/admin` (pulpit),
`/admin/cinemas`, `/admin/cinemas/{cinema}/halls`, `/admin/halls/{hall}/layout`,
`/admin/movies`, `/admin/cinemas/{cinema}/screenings` (siatka tygodnia),
`/admin/cinemas/{cinema}/screenings/copy`, `/admin/bookings`,
`/admin/bookings/{booking}`, `/admin/screenings/{screening}/seats`,
`/admin/articles` oraz `POST /admin/broadcasting/auth`. Trasy panelu nie trafiają
do `/docs/api` (Scramble dokumentuje wyłącznie `api/v1`).

### Zasoby frontu panelu bez kroku budowania

Pico CSS 2.1.1, `laravel-echo` 2.4.0 i `pusher-js` 8.6.0 leżą w
`backend/public/vendor/admin/` razem z `SHA256SUMS` i licencjami. Pobiera je
`tools/admin-assets/fetch.sh`: Node w kontenerze przypiętym **po digeście**,
`npm ci` z `package-lock.json`, pliki należą do użytkownika WSL. Własny JS panelu
to dwa pliki: `public/js/admin/hall-layout-editor.js` (Alpine) i
`public/js/admin/realtime.js` (Echo).

### Cache katalogu w Redisie: liczniki generacji

`CatalogCache` trzyma repertuar dnia, kalendarz dni, listę kin, listę filmów
i artykuły. **Klucz = nazwa + numery generacji**, od których zależą dane:

```text
catalog:repertoire:day:c3:2026-09-20:g2.5.11      (epoka . kino 3 . filmy)
```

- **Inwalidacja to `increment()` licznika** — jedno `INCRBY`, atomowe, bez `KEYS`,
  `SCAN` i tagów. Stare klucze przestają być czytane i wygasają po TTL
  (`CATALOG_CACHE_TTL`, 600 s) — TTL jest sprzątaniem, nie mechanizmem.
- **Kolejność:** generacje czytamy **przed** zapytaniem do bazy, podbijamy je
  **po COMMIT** (`DB::afterCommit`). Podbicie przed COMMIT pozwoliłoby czytelnikowi
  zapisać stare dane pod nową generacją na cały TTL; po ROLLBACK nic się nie podbija.
- **Generacje:** `epoch` (podbija `DatabaseSeeder` — po `migrate:fresh --seed`
  id zaczynają się od nowa), `cinema:{id}` (sale, układy, seanse, cenniki, nazwa
  i strefa kina), `cinemas`, `movies`, `articles`. Zmiana pośrednia też podbija:
  `ScreeningLifecycleService` kończy seanse jednym `UPDATE` i podbija generacje kin.
- **Tylko tablice i skalary.** `config/cache.php` ma `serializable_classes = false`,
  więc model zapisany w Redisie wraca po cichu jako `__PHP_Incomplete_Class`.
  `CatalogCache` rzuca wyjątek już przy próbie zapisu obiektu, a `RepertoireService`
  odtwarza modele przez `newFromBuilder()` i `setRelation()` — kontrolery, zasoby
  i testy API z Etapu 3 zostały bez zmian.
- **Poza cache:** liczniki sprzedanych i zablokowanych miejsc oraz wszystko, co zależy
  od „teraz” (seans rozpoczęty, artykuł zaplanowany) — liczone przy odczycie.
  Premiera nie unieważnia cache co sekundę.
- **Stampede:** po podbiciu liczy tylko ten, kto zdobędzie zamek; reszta czeka do 2 s
  i czyta gotowy wynik. Brak zamka w czasie = wynik bez zapisu (*fail-open*).
- **Listy bez kluczy per strona:** `MovieCatalogService` i `ArticleCatalogService`
  trzymają jedną listę i wycinają stronę w PHP — parametry `?page`, `?per_page`
  i `?type` nie mnożą kluczy. W cache nie ma adresów URL plakatów (zatrucie cache
  nagłówkiem `Host` jednego żądania).

### Struktura: kina, sale, edytor układu

- **Bez twardego usuwania** kin, sal i filmów — `is_active`. Wyłączenie kina, sali
  i filmu, zmiana strefy czasowej kina, typu projekcji sali i czasu trwania filmu
  są zablokowane, dopóki istnieją nadchodzące seanse (409, `StructureChangeBlockedException`
  z kodami `CINEMA_HAS_UPCOMING_SCREENINGS`, `CINEMA_TIMEZONE_LOCKED`,
  `HALL_HAS_UPCOMING_SCREENINGS`, `HALL_PROJECTION_TYPE_IN_USE`,
  `MOVIE_HAS_UPCOMING_SCREENINGS`, `MOVIE_DURATION_LOCKED`).
- **Slug kina nadawany raz** — adres zapamiętany przez klientów nie psuje się po zmianie nazwy.
- **Edytor układu** (`HallLayoutEditor`): generator prostokąta z przejściami
  (`HallLayoutGenerator`), potem klikanie w siatkę w Alpine — bez żądania na każdy
  fotel. Serwer dostaje cały układ przy zapisie i waliduje go w całości
  (`HallLayoutService`): nakładające się kratki, miejsce podwójne zajmujące
  kratki `x` i `x+1`, kategorie, pusty układ (`HALL_LAYOUT_INVALID`, z listą wszystkich problemów).
- **Dwa tryby zapisu układu.** *Pełny* — sala bez historii sprzedaży i bez
  nadchodzących seansów: `DELETE` + `INSERT`, rzędy i numery od nowa. *Ograniczony* —
  tożsamość miejsc zamrożona (id, pozycja, rząd, numer, szerokość), wolno zmienić
  kategorię, typ standard ↔ dla niepełnosprawnych i dostępność. Bilet z przeszłości
  dalej wskazuje „B7”. Strażnicy sprzedaży: miejsce z blokadą albo płatnością w toku
  (`HALL_LAYOUT_SEATS_HELD`), sprzedane (`HALL_LAYOUT_SEATS_SOLD`), kategoria bez
  ceny w cenniku nadchodzącego seansu (`HALL_LAYOUT_PRICES_MISSING`). Po zapisie
  `SeatsResync` dla nadchodzących seansów i generacja kina.

### Filmy i plakaty

- **Przekodowanie, a nie zapis 1:1** (`PosterImageProcessor`): tylko JPG i PNG,
  `getimagesize()` przed dekodowaniem i limit 16 Mpx (bomba dekompresyjna nie
  zaalokuje pamięci), minimum 300 × 450 px, wynik JPEG q85 w ramce 800 × 1200 na
  białym tle. Nowy plik nie niesie EXIF-u ani danych doklejonych za obrazem.
  GD w obrazie nie obsługuje WebP i AVIF, stąd tylko JPG i PNG.
- **Plik i wiersz bez wspólnej transakcji** (`MovieAdminService`): obraz zapisany
  przed transakcją pod nową nazwą `posters/{ulid}.jpg`, stary usuwany po COMMIT,
  nowy przy ROLLBACK. Awaria pośrodku zostawia osierocony plik, nigdy wiersz
  wskazujący plik, którego nie ma.
- **Limity:** reguła `max:5120` (5 MB), a PHP przyjmuje do 8 MB
  (`docker/php/conf.d/uploads.ini`, podpięty jako `zz-uploads.ini`) — plik 6 MB
  dostaje polski komunikat walidacji zamiast cichego odrzucenia przez PHP.
- **Dysk `public`** z względnym dowiązaniem `public/storage`; dysk `local` ma
  `'serve' => false`, bo trasa `storage/{path}` zajmowała ten sam prefiks.
  `APP_URL` wskazuje port nginx (8080) — adresy plakatów w mailach i kolejce.
- **`GET /api/v1/movies`** — aktywne filmy, najnowsze premiery pierwsze, bez opisu.

### Repertuar: seanse, kolizje, siatka, kopiowanie

- **`ScreeningTimeline` to jedyne miejsce liczenia czasu seansu** (panel, seeder,
  fabryka): `starts_at` → reklamy → `ends_at` → sprzątanie → `slot_ends_at`.
  Bufory są globalne (`SCREENING_ADS_MINUTES`, `SCREENING_CLEANUP_BUFFER_MINUTES`)
  i działają na nowe seanse — istniejące mają zapisane końce.
- **Kolizje w trzech warstwach, jedna definicja** (`ScreeningSlot::overlaps`,
  przedział półotwarty): blokada wiersza sali szereguje zmiany repertuaru i układu;
  pod blokadą zapytanie zwraca **listę kolidujących seansów** (409 `SCREENING_CONFLICT`);
  constraint `screenings_no_overlap` łapie resztę, a SQLSTATE `23P01` tłumaczymy
  na ten sam wyjątek.
- **Godzina w strefie kina.** `ScreeningTimeline::localStart()` odrzuca godziny,
  których nie ma przy zmianie czasu na letni, i podwójne przy zmianie na zimowy
  (`SCREENING_INVALID`).
- **Seans ze sprzedażą jest zamrożony** (`SCREENING_HAS_SALES`, `SCREENING_HAS_BOOKINGS`):
  `FOR UPDATE` na wierszu seansu czeka na `INSERT` do `seat_locks`, `bookings`
  i `tickets`, bo klucz obcy bierze `FOR KEY SHARE` na tym samym wierszu.
  Kolejność blokad: sale rosnąco → kino `FOR SHARE` → film `FOR SHARE` → seans.
- **Ceny w złotych jako tekst** („25,50”) zamieniane na grosze bez liczby
  zmiennoprzecinkowej.
- **Siatka tygodnia** (`ScreeningWeek`): sale × 7 dni w strefie kina, liczba
  sprzedanych biletów z linkiem do planu sali; obsługa kina widzi siatkę swojego
  kina bez edycji (`CinemaPolicy::viewRepertoire`).
- **Kopiowanie dnia** (`RepertoireCopyService`): każdy seans przez
  `ScreeningAdminService::create()` w osobnym SAVEPOINT — te same reguły co ręcznie.
  Wszystko albo nic (`REPERTOIRE_COPY_BLOCKED` z pełnym raportem), podgląd to ten
  sam przebieg zakończony ROLLBACK, seans identyczny z istniejącym oznaczony „już jest”
  (podwójne kliknięcie niczego nie dubluje), blokada doradcza na kino i dzień docelowy.
  Godzina lokalna zostaje ta sama także przez zmianę czasu.

### Sprzedaż: lista rezerwacji i plan sali

- **Lista** (`BookingIndex`): filtry w adresie (`kino`, `data`, `film`, `status`, `nr`),
  paginacja, numer rezerwacji po prefiksie ULID (min. 4 znaki). Dzień seansu w strefie
  **tego** kina: `(screenings.starts_at AT TIME ZONE cinemas.timezone)::date`.
  Relacje ładowane z góry — `preventLazyLoading` wywraca test przy zapomnianym `with()`.
- **Dane osobowe:** administrator widzi pełny e-mail klienta, obsługa kina
  zamaskowany (`PersonalData::maskEmail`, `a***@example.com`). Identyfikator płatności
  Stripe tylko w końcówce.
- **Plan sali seansu** (`ScreeningSeatPlan`): ten sam `SeatMapService` co publiczne API,
  bez sesji klienta; sprzedane miejsce linkuje do rezerwacji, cudza blokada zostaje
  anonimowa. Seans w sprzedaży odświeża się co 10 s.

### Jedna kolejność blokad

Deadlock powstaje, gdy dwie transakcje blokują te same wiersze w różnej kolejności.
Reguły po Etapie 7:

1. **`seat_locks`:** każde `SELECT … FOR UPDATE` idzie przez scope
   `SeatLock::scopeInLockOrder()` (w zapytaniach `->inLockOrder()`) = `ORDER BY screening_id, seat_id` (checkout, `fulfil()`,
   `finish()`, zwalnianie, sprzątanie). Wśród niezwolnionych blokad para jest unikalna,
   więc kolejność jest całkowita także w porcji sprzątania wielu seansów.
2. **Między tabelami:** rezerwacja → `seat_locks` albo bilety → wersja stanu miejsc.
   Dlatego checkout przy podwójnym kliknięciu tylko **czyta** wiersz rezerwacji
   (trzyma już `seat_locks`), a anulowanie opłaconej rezerwacji blokuje bilety
   **przed** sprawdzeniem „żaden nie wykorzystany” — skan przy wejściu czeka na nie.

Obie reguły sprawdzają testy na logu zapytań (`SeatLockOrderingTest`,
`AdminBookingCancellationTest`).

### Anulowanie rezerwacji przez administratora ze zwrotem

```text
BookingService::cancelByAdmin()  ── transakcja: status cancelled, powód, kto, bilety cancelled,
        │                           miejsca wolne (wersja stanu miejsc), refund_requested_at
        ▼  po COMMIT
PaymentService::settleRefund()   ── retrieveIntent: da się anulować → cancelIntent (klucz :cancel)
        │                                          succeeded        → refundIntent (klucz :refund)
        │                                          processing       → zostaw
        ▼
BookingService::completeRefund() ── transakcja: refund_completed_at; status refunded tylko gdy
                                    pieniądze wróciły, void zostawia cancelled
```

- **Miejsca wracają do sprzedaży od razu**, niezależnie od operatora płatności.
  Porażka rozmowy ze Stripe'em nie cofa anulowania: rezerwacja zostaje na liście
  zaległych (`refund_requested_at` bez `refund_completed_at`, indeks częściowy
  `bookings_refund_pending`), a komenda `cinema:bookings:retry-refunds` ponawia ją co
  5 minut (rozliczenia starsze niż 120 s — świeże rozlicza panel).
- **Decyzja z bieżącego stanu płatności**, a nie z naszej bazy: status `paid` nie mówi,
  czy capture już się odbył (`fulfil()` ustawia go przed capture). Te same klucze
  idempotencji co w webhooku i wygaszaniu = żadnego podwójnego zwrotu; po wygaśnięciu
  klucza (24 h) kod `charge_already_refunded` też oznacza sukces.
- **Webhook po anulowaniu:** rezerwacja z `refund_requested_at` dostaje wynik
  `admin_cancelled` — spóźnione `amount_capturable_updated` nie pobierze pieniędzy,
  a `succeeded` nie zleci drugiego zwrotu. `fulfil()` uznaje istnienie biletów za
  „już opłacone” tylko przy statusie `paid`.
- **Kiedy nie wolno:** status inny niż `pending`/`paid` (`BOOKING_NOT_CANCELLABLE`),
  seans opłaconej rezerwacji już się zaczął (`BOOKING_SCREENING_STARTED`), bilet
  wykorzystany (`BOOKING_TICKETS_USED`), powód krótszy niż 10 albo dłuższy niż 255
  znaków (`CANCELLATION_REASON_INVALID`, 422). Anuluje wyłącznie administrator
  (`BookingPolicy::cancel`).
- **Powód to notatka wewnętrzna:** widzą go tylko administratorzy w panelu. Nie ma
  go w mailu do klienta (`BookingCancelledByCinema`, kolejka, `shouldSend()` w chwili
  wysyłki), w logach, w feedzie ani w API klienta — `GET /api/v1/bookings/{booking}`
  zwraca `cancellation.cancelled_at` i `cancellation.refund` (`none`, `pending`, `refunded`).

### Pulpit i feed sprzedaży na żywo

| Liczba | Definicja (`SalesDashboardService`) |
|---|---|
| sprzedaż brutto | rezerwacje opłacone dziś (`paid_at`) ze statusem `paid` albo `refunded` — pieniądze wpłynęły |
| zwroty | rezerwacje `refunded` ze zwrotem zakończonym dziś (`refund_completed_at`) |
| netto | brutto minus zwroty (zwrot może dotyczyć wczorajszej sprzedaży) |
| bilety | nieanulowane bilety rezerwacji opłaconych dziś |
| obłożenie | nieanulowane bilety / aktywne miejsca sali, seanse zaczynające się dziś |
| top 5 filmów | nieanulowane bilety z rezerwacji opłaconych w ostatnich 7 dobach |

- **„Dziś” to doba w strefie każdego kina.** Kina grupujemy po strefie, dla każdej
  liczymy przedział półotwarty [północ, następna północ) w UTC i łączymy warunkiem
  `OR` — porównanie z `paid_at` trafia w indeks `bookings_paid_at`. Następną północ
  liczymy w strefie kina, więc doba zmiany czasu ma 23 albo 25 godzin (test na 25.10.2026).
  Bez cache: kilka zapytań po indeksach, a pulpit ma pokazywać stan z tej chwili.
- **Feed przez WebSocket:** `Dashboard` nasłuchuje `echo-private:sales,.sales.activity`
  (administrator) albo kanału `cinemas.{id}.sales` (obsługa). Podpis kanału wydaje
  `POST /admin/broadcasting/auth` — sesja i CSRF zamiast tokenu, ale **ta sama**
  `ChannelAuthorizationService` co w API, więc reguła „kto słucha feedu kina” jest
  zapisana raz. Limit `panel-broadcasting-auth`: 30/min na użytkownika.
- **Zdarzenie z przeglądarki to tylko sygnał.** Argument metody nasłuchującej wysyła
  klient, więc bierzemy z niego wyłącznie numer rezerwacji i status, a treść wpisu
  czytamy z bazy w zakresie zalogowanego użytkownika. Zdarzenie z cudzego kina jest
  ignorowane.
- **`realtime.js`** ładuje się tylko na pulpicie, w `<head>` przed skryptem Livewire
  (Livewire zakłada nasłuchy przy starcie komponentu). Adres WebSocketu = adres strony,
  bo nginx przekazuje `/app/` do Reverba. Status połączenia widać w nagłówku pulpitu.
- **Rezerwa:** `wire:poll.60s`, gdy WebSocket nie działa. Po odzyskaniu połączenia
  `realtime.js` wysyła `realtime-reconnected`, a pulpit odtwarza feed z bazy — zdarzenia
  z czasu przerwy przepadły.

### Moduł informacyjny: artykuły

- **Tabela `articles`:** `type` (`news` „Aktualności”, `premiere` „Nadchodzące premiery”),
  `status` (`draft`, `published`), `published_at`, `excerpt`, `body` (Markdown),
  `movie_id`, `author_id`, niezmienny `slug`. CHECK-i w bazie: opublikowany ma datę
  (`articles_published_has_date`), premiera wskazuje film (`articles_premiere_has_movie`).
- **„Zaplanowany” to nie osobny status**, tylko `published` z datą w przyszłości.
- **Publiczne API:** `GET /api/v1/articles` (filtr typu, paginacja)
  i `GET /api/v1/articles/{slug}` z `body_html`. Filtr `type`: `news` albo `premiere`. Szkic i artykuł zaplanowany dają 404
  jak nieistniejący.
- **Cache (`ArticleCatalogService`):** jeden klucz z **wszystkimi** opublikowanymi
  artykułami, także zaplanowanymi, bez treści; filtr „data minęła”, typ i stronę liczymy
  przy odczycie. Zaplanowany artykuł pojawia się sam o swojej godzinie — bez zadania
  w harmonogramie i bez TTL dopasowanego do najbliższej publikacji. Treść ma osobny klucz
  per artykuł z gotowym HTML-em. O istnieniu artykułu decyduje lista z cache: nieznany
  slug dostaje 404 bez zapytania do bazy i bez zakładania nowego klucza.
- **Bezpieczny HTML (`ArticleMarkdown`):** `html_input = strip` (surowy HTML znika),
  `allow_unsafe_links = false` (`javascript:`, `data:` tracą `href`). Vue i Flutter wstawią
  `body_html` jako HTML, więc to granica bezpieczeństwa klientów — test jednostkowy na
  10 przypadków złośliwego wejścia. W bazie trzymamy Markdown, nie HTML: zmiana reguł
  sanityzacji nie wymaga przepisania wierszy.
- **Panel:** lista z kolumną „Widoczność” (szkic / zaplanowany / widoczny), formularz
  z podglądem liczonym tym samym `ArticleMarkdown`, data publikacji w strefie
  Europe/Warsaw (`ArticleAdminService::TIMEZONE`) z odrzuceniem godzin nieistniejących
  przy zmianie czasu, twarde usuwanie (artykułu nie wskazuje żadna sprzedaż).
  `ArticleSeeder` dodaje 5 przykładowych artykułów.

### Nowe endpointy API i kody błędów

| Metoda | Ścieżka | Opis |
|---|---|---|
| GET | `/api/v1/movies` | aktywne filmy sieci, paginacja (`per_page` do 50), cache |
| GET | `/api/v1/articles` | opublikowane artykuły, filtr `type` (`news` / `premiere`), paginacja, cache |
| GET | `/api/v1/articles/{slug}` | artykuł z `body_html`, cache |

Zmiana w istniejącym: `GET /api/v1/bookings/{booking}` — w `cancellation` zamiast
powodu jest stan zwrotu `refund`.

| Kod | HTTP | Kiedy |
|---|---|---|
| `SCREENING_INVALID` | 422 | godzina nieistniejąca albo podwójna przy zmianie czasu, seans w przeszłości, sala wyłączona, zły cennik |
| `SCREENING_CONFLICT` | 409 | seans nachodzi na inny w tej sali (lista kolizji w `context`) |
| `SCREENING_HAS_SALES` / `SCREENING_HAS_BOOKINGS` / `SCREENING_NOT_EDITABLE` / `SCREENING_STALE` | 409 | zmiana albo odwołanie seansu ze sprzedażą, zakończonego lub zmienionego w międzyczasie |
| `REPERTOIRE_COPY_BLOCKED` | 409 | kopiowanie dnia z choćby jednym problemem (raport w `context`) |
| `HALL_LAYOUT_INVALID` | 422 | układ z nakładającymi się miejscami, nieznaną kategorią, pusty |
| `HALL_LAYOUT_RESTRICTED` / `HALL_LAYOUT_SEATS_HELD` / `HALL_LAYOUT_SEATS_SOLD` / `HALL_LAYOUT_PRICES_MISSING` | 409 | zmiana układu sali z historią albo nadchodzącą sprzedażą |
| `CINEMA_HAS_UPCOMING_SCREENINGS` / `CINEMA_TIMEZONE_LOCKED` / `HALL_HAS_UPCOMING_SCREENINGS` / `HALL_PROJECTION_TYPE_IN_USE` / `HALL_NAME_TAKEN` | 409 | zmiana struktury przy nadchodzących seansach, nazwa sali zajęta w kinie |
| `MOVIE_HAS_UPCOMING_SCREENINGS` / `MOVIE_DURATION_LOCKED` | 409 | wyłączenie filmu albo zmiana czasu trwania przy nadchodzących seansach |
| `POSTER_INVALID` | 422 | plik nie jest JPG/PNG, za mały, ponad 16 Mpx, nieczytelny |
| `BOOKING_NOT_CANCELLABLE` / `BOOKING_SCREENING_STARTED` / `BOOKING_TICKETS_USED` | 409 | anulowanie rezerwacji niedozwolone |
| `CANCELLATION_REASON_INVALID` | 422 | powód spoza 10–255 znaków |
| `ARTICLE_INVALID` | 422 | premiera bez filmu, nieznany film, zła godzina publikacji |
| `PANEL_LOGIN_THROTTLED` | 429 | za dużo nieudanych logowań do panelu |

### Infrastruktura i konfiguracja

- `docker/php/conf.d/uploads.ini` podpięty w kotwicy `x-php-app` jako
  `/usr/local/etc/php/conf.d/zz-uploads.ini:ro` (`upload_max_filesize = 8M`,
  `post_max_size = 10M`). Po zmianie pliku: `docker compose up -d --force-recreate php worker scheduler reverb`,
  potem `docker compose restart nginx`.
- `backend/phpunit.xml`: `memory_limit` 512M dla całego zestawu testów.
- Harmonogram: nowa komenda `cinema:bookings:retry-refunds` co 5 minut
  (`withoutOverlapping`, `onOneServer`, wyjście do logu schedulera).

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `APP_URL` | `http://localhost:8080` | port nginx — adresy plakatów poza żądaniem HTTP |
| `SESSION_CONNECTION` / `REDIS_SESSION_DB` | `session` / `2` | sesje panelu w osobnej bazie Redisa |
| `CATALOG_CACHE_TTL` | `600` | sekundy; zabezpieczenie i sprzątanie cache katalogu |
| `SCREENING_ADS_MINUTES` / `SCREENING_CLEANUP_BUFFER_MINUTES` | `15` / `20` | bufor reklam i sprzątania w slocie seansu |

### Etap 7 — decyzje projektowe (128–186)

128. **Livewire 4 z komponentami klasowymi** i layoutem `layouts::admin`; panel bez SPA i bez kroku budowania.
129. **Sesja dla panelu, tokeny Sanctum dla API** — dwa wejścia, jeden model `User`.
130. **Panel dla administratora i obsługi kina**; Gate `panel.access` jako bramka, decyzje w Policies.
131. **Limit liczy tylko porażki** (5/min konto+IP, 20/min IP), jeden komunikat odmowy, hash także dla nieistniejącego konta.
132. **`regenerate()` po logowaniu, `invalidate()` + `regenerateToken()` przy wylogowaniu** — session fixation w teście.
133. **Sesje w Redisie DB 2** — osobno od kolejki (DB 0) i cache (DB 1).
134. **Pico CSS i przypięte pliki JS w repozytorium**, pobierane skryptem z Node po digeście i `npm ci`, z sumami SHA256.
135. **`authorize()` w każdej akcji komponentu, `#[Locked]` na identyfikatorach**, rekord ładowany od nowa w akcji.
136. **Cache katalogu na licznikach generacji** zamiast tagów i `KEYS`.
137. **Generacje czytane przed zapytaniem, podbijane po COMMIT.**
138. **W cache tylko tablice i skalary**; modele odtwarzane `newFromBuilder()` / `setRelation()`.
139. **Zamek przeciw stampede, fail-open bez zapisu.**
140. **Liczniki sprzedaży i wszystko zależne od „teraz” poza cache**, liczone przy odczycie.
141. **Epoka podbijana przez `DatabaseSeeder`**; zmiana pośrednia ze schedulera też podbija generacje.
142. **TTL 600 s jako zabezpieczenie**; nigdy `Cache::flush()` w kodzie aplikacji (bezpiecznik Reverba i limitery w tym samym store).
143. **Bez twardego usuwania kin, sal i filmów**; wyłączenie i zmiany wpływające na sprzedaż zablokowane przy nadchodzących seansach (409).
144. **Slug kina nadawany raz.**
145. **Nowa sala ma siatkę 0 × 0**; miejsca i wymiary nadaje edytor układu.
146. **Szkic układu w Alpine, walidacja całości na serwerze** przy zapisie.
147. **Dwa tryby zapisu układu**: pełny dla sali bez historii i nadchodzących seansów, ograniczony z zamrożoną tożsamością miejsc.
148. **Strażnicy sprzedaży przy zmianie układu** (blokady, sprzedane miejsca, cennik); po zapisie `SeatsResync` i generacja kina.
149. **Miejsce podwójne zajmuje kratki `x` i `x+1`** — konwencja seedera sprawdzana przez walidację układu.
150. **Plakat przekodowany do JPEG** (JPG/PNG, ≤ 16 Mpx, ≥ 300 × 450, ramka 800 × 1200); wymiary z nagłówka przed dekodowaniem.
151. **Plik plakatu przed transakcją, nowa nazwa przy każdej zmianie**, stary usuwany po COMMIT, nowy przy ROLLBACK.
152. **Limity uploadu PHP w pliku ini podpiętym jako wolumen** (8M/10M) przy regule aplikacji 5 MB.
153. **Dysk `local` z `'serve' => false`, względne `public/storage`, `APP_URL` na porcie nginx.**
154. **`GET /api/v1/movies` z jedną listą w cache** i stroną wycinaną w PHP; bez adresów URL w cache.
155. **`ScreeningTimeline` jako jedyne miejsce liczenia slotu**; bufor globalny, zmiana działa na nowe seanse.
156. **Kolizje w trzech warstwach** (blokada sali, zapytanie z listą, `EXCLUDE` + `23P01`) z jedną definicją nakładania.
157. **Godzina seansu w strefie kina**; godziny nieistniejące i podwójne przy zmianie czasu odrzucane.
158. **Seans ze sprzedażą zamrożony, `FOR UPDATE` na seansie** — `INSERT` z kluczem obcym czeka na tę blokadę.
159. **Kolejność blokad planowania**: sale rosnąco → kino `FOR SHARE` → film `FOR SHARE` → seans.
160. **Ceny w złotych jako tekst → grosze bez float.**
161. **Siatka tygodnia w strefie kina**; obsługa widzi siatkę swojego kina bez edycji.
162. **Kopiowanie dnia przez `ScreeningAdminService::create()` w SAVEPOINT-ach**: wszystko albo nic, podgląd z ROLLBACK, „już jest” zamiast duplikatu, blokada doradcza na dzień docelowy.
163. **Zakres obsługi kina liczony na serwerze przy każdym renderze**; e-mail klienta zamaskowany dla obsługi.
164. **Dzień seansu w SQL w strefie kina z wiersza** (`AT TIME ZONE cinemas.timezone`) dla filtra listy rezerwacji.
165. **Plan sali w panelu z `SeatMapService` bez sesji**; numer rezerwacji tylko przy sprzedanym miejscu, odświeżanie co 10 s.
166. **Jedna kolejność blokowania `seat_locks`**: `screening_id, seat_id` przez scope; `SELECT … FOR UPDATE`, potem `UPDATE` po id.
167. **Kolejność między tabelami: rezerwacja → `seat_locks`/bilety → wersja miejsc**; checkout nie blokuje wiersza rezerwacji.
168. **Anulowanie w trzech krokach: baza → operator → baza**; porażka operatora nie cofa anulowania.
169. **Rozliczenie z bieżącego stanu płatności u operatora** (void, zwrot albo ponowienie) z tymi samymi kluczami idempotencji.
170. **Dwa znaczniki rozliczenia + indeks częściowy + CHECK** zamiast statusu; komenda ponawiająca co 5 minut.
171. **Webhook na rezerwacji anulowanej przez administratora → `admin_cancelled`**; `fulfil()` idempotentne tylko dla `paid`.
172. **Bilety blokowane przed sprawdzeniem „wykorzystany”** — wyścig ze skanerem przy wejściu.
173. **Status `refunded` tylko po zwrocie pieniędzy**; zwolniona autoryzacja zostawia `cancelled`.
174. **Powód anulowania to notatka wewnętrzna** (10–255 znaków): poza mailem, logami, feedem i API klienta; klient dostaje stan zwrotu.
175. **Anulowanie wyłącznie przez administratora**, opłaconej tylko przed seansem i bez wykorzystanego biletu.
176. **`POST /admin/broadcasting/auth` z sesją i CSRF, ta sama `ChannelAuthorizationService`**; limiter 30/min na użytkownika.
177. **`ForceJsonResponse` z priorytetem przed uwierzytelnieniem** — błędy autoryzacji kanału w JSON-ie jak w API.
178. **Echo z przypiętego IIFE, adres WebSocketu = adres strony**, skrypty tylko na pulpicie przed Livewire.
179. **Zdarzenie z przeglądarki jako sygnał, wpis feedu z bazy** w zakresie użytkownika.
180. **„Dziś” w strefie każdego kina** przez przedziały półotwarte w UTC na strefę; pulpit bez cache.
181. **`wire:poll.60s` jako rezerwa i odtworzenie feedu po reconnect.**
182. **Indeksy pulpitu**: częściowe `bookings_paid_at`, `bookings_refund_completed_at` i `bookings_updated_at`.
183. **Markdown w bazie, HTML przy odczycie przez `ArticleMarkdown`** (bez surowego HTML-a i niebezpiecznych linków).
184. **„Zaplanowany” = opublikowany z przyszłą datą**; lista w cache filtrowana czasem przy odczycie.
185. **Treść artykułu w osobnym kluczu; 404 rozstrzyga lista z cache**, bez nowych kluczy dla losowych adresów.
186. **Niezmienny slug artykułu, data publikacji w strefie Europe/Warsaw, twarde usuwanie**, CHECK-i publikacji i premiery w bazie.

### Etap 7 — pułapki, na które trafiliśmy (AW–BS)

- **AW. Pusty raport przechodzi strażnika „zero braków”.** Skrypt zgodności przeczytał
  README bez sekcji etapu i wypisał „OK: 0, BRAK: 0”. Strażnik wymaga niepustego wyniku.
- **AX. Ciasteczko sesji nazywa się „laravel-session”** (slug `APP_NAME` z myślnikiem),
  więc skan sekretów szukający „laravel_session” go nie widział. Wzorzec skanu: „laravel[-_]session”.
- **AY. Sesje w Redisie bez `SESSION_CONNECTION` lądują w DB 0** razem z kolejką
  i muteksami harmonogramu. Osobne połączenie `session` na DB 2.
- **AZ. `redirectGuestsTo()` dla panelu zamieniłoby 401 JSON API w przekierowanie**
  na formularz logowania. Callback zwraca `null` dla `api/*`; test regresji w `PanelAccessTest`.
- **BA. `Livewire::test` działa bez middleware trasy.** `can:` na trasie nie chroni
  komponentu w teście ani w żądaniach aktualizacji — broni tylko `authorize()` w komponencie.
- **BB. `serializable_classes = false` (Laravel 13):** model zapisany w Redisie wraca po
  cichu jako `__PHP_Incomplete_Class`, bez wyjątku. W testach store `array` bez serializacji
  to ukrywa — testy cache włączają `serialize`.
- **BC. 419 bez tokenu CSRF nie da się sprawdzić w PHPUnit** — weryfikacja CSRF jest pomijana,
  gdy działają testy, a `Livewire::test` wyłącza middleware. 419 sprawdzają testy dymne przez nginx.
- **BD. Zmiana pośrednia omija inwalidację.** Scheduler kończy seanse jednym `UPDATE`,
  bez serwisu panelu — bez jawnego podbicia generacji repertuar w cache pokazywałby seans do końca TTL.
- **BE. Identyfikatory commitów lustra różnią się od repozytorium**, więc strażnik „HEAD to X”
  w skrypcie paczki zatrzymał poprawny blok. Stan sprawdzamy sumami SHA256 plików przed łatką.
- **BF. `validate()` zatrzymuje się na wcześniejszym polu** — test przejść w generatorze układu
  padał na brakującej kategorii cenowej, a nie na sprawdzanej regule.
- **BG. Po 403 w `Livewire::test` komponent nie ma migawki** — każda kolejna akcja wymaga nowej instancji.
- **BH. Dwa przyciski `radio` z tą samą wartością** (`standard`) w edytorze układu — wybór
  typu miejsca był niejednoznaczny; testy komponentu tego nie widzą, wykrył to dopiero Playwright.
- **BI. Pojedynczy plik podpięty jako wolumen jest związany z i-węzłem.** Edytor, `sed -i`
  i `git checkout` zapisują nowy plik, a kontener widzi stary — potrzebne `--force-recreate`.
- **BJ. Po odtworzeniu kontenera `php` trzeba zrestartować nginx** — `fastcgi_pass php:9000`
  rozwiązuje adres tylko przy starcie nginx.
- **BK. `UploadedFile::fake()` żyje tyle, co obiekt** — plik tymczasowy znika razem z nim;
  test musi trzymać referencję do końca.
- **BL. Statyczny licznik slotów w `ScreeningFactory` przechodzi między testami** — seanse
  przesuwały się o dni, a podróż w czasie dalej niż TTL gasiła cache. Reset w `TestCase::setUp()`.
- **BM. Endpoint uploadu Livewire w teście** wymaga podpisu względnego, `Accept: application/json`
  i `Storage::fake('tmp-for-tests')` — inaczej 401, 302 albo 500.
- **BN. Laravel formatuje daty dla PostgreSQL jako `Y-m-d H:i:s` bez strefy.** Carbon w strefie
  kina trafiał do kolumny `timestamptz` jako czas UTC — przesunięcie o 2 godziny w seederze i granicach
  dnia repertuaru. Do zapytań i zapisów tylko Carbon w UTC.
- **BO. `update()` / `fill()` po cichu pomija pola spoza `$fillable`** — test zmieniał kolumnę,
  której zmiana nigdy nie doszła do bazy.
- **BP. `INSERT` z kluczem obcym bierze `FOR KEY SHARE` na wierszu rodzica.** Konfliktuje
  z `FOR UPDATE`, ale nie z `FOR NO KEY UPDATE`, które bierze zwykły `UPDATE` — dlatego
  zamrożenie seansu wymaga jawnego `lockForUpdate()`.
- **BQ. Pełny zestaw testów w jednym procesie PHP przekroczył 128 MB** — obrazy GD liczą się
  do `memory_limit` CLI. `memory_limit` 512M w `phpunit.xml`.
- **BR. Kolejność middleware na trasie nie jest gwarantowana.** Laravel sortuje je według listy
  priorytetów i przesuwa `auth` przed middleware spoza listy — `ForceJsonResponse` trzeba było
  dopisać do priorytetów (`prependToPriorityList`).
- **BS. OPcache sprawdza pliki co „opcache.revalidate_freq” (2 s)** — żądanie tuż po łatce trafiło
  w stary kod (404 nowej trasy, 500 widoku). Testy dymne czekają 3 s na starcie.

### Etap 7 — testy

| Klasa testu | Liczba | Obszar |
|---|---:|---|
| `PanelAccessTest` | 20 | macierz dostępu gość / klient / obsługa / administrator, session fixation, jeden komunikat odmowy, limity per konto i per IP, `authorize()` w komponencie, regresja 401 w API |
| `CatalogCacheTest` | 9 | generacje (także niezwiązane i epoka), pusta tablica jako trafienie, podbicie po COMMIT i brak po ROLLBACK, dowód pułapki BB, odrzucenie obiektów, zamek i fail-open |
| `RepertoireCacheTest` | 8 | identyczny JSON z cache, odtworzone modele z rzutowaniami, generacje filmów, kina i listy kin, liczniki miejsc na żywo, seans zakończony przez scheduler znika, kalendarz filtrowany czasem |
| `CinemaManagementTest` | 11 | trasy i komponenty tylko dla administratora, `#[Locked]`, wyszukiwanie z `%` dosłownie, walidacja, unikalny i stały slug, blokady strefy i wyłączenia, wyłączone kino znika z API od razu |
| `HallManagementTest` | 8 | typy projekcji (kolejność, wymagane, w użyciu), nazwa unikalna w kinie, blokada wyłączenia, sala innego kina, komponenty bez middleware, nowa nazwa w repertuarze od razu |
| `HallLayoutGeneratorTest` | 4 | przejścia jak w seederze, sala jednorzędowa, przejście poza rzędem, rząd szerszy niż siatka |
| `HallLayoutServiceTest` | 9 | tryb pełny z numeracją bez luk, lista wszystkich błędów bez zmian, przejście w tryb ograniczony, zmiany w miejscu i `SeatsResync`, strażnicy blokady, sprzedaży i cennika, odwołany seans nie ogranicza |
| `HallLayoutEditorTest` | 8 | dostęp, generator bez zapisu i w trybie ograniczonym, zapis przez serwis, lista błędów, uprawnienia przy każdym wywołaniu, `#[Locked]` |
| `PosterImageProcessorTest` | 8 | skalowanie bez powiększania, przezroczysty PNG na białym tle, bez metadanych i doklejonych bajtów, bomba dekompresyjna odrzucona z nagłówka, za mały obraz, fałszywe i ucięte pliki |
| `MovieManagementTest` | 16 | plakat jako przeskalowany JPEG, walidacja zaraz po wgraniu i w endpoincie uploadu Livewire, bomba dekompresyjna, slug, `#[Locked]`, stary plik po COMMIT, nowy usuwany po błędzie, usuwanie tylko z `posters/`, blokady |
| `MovieListApiTest` | 4 | tylko aktywne, paginacja, cache i inwalidacja, adres plakatu z hosta żądania |
| `ScreeningTimelineTest` | 20 | **test jednostkowy kolizji (wymóg 5.2)**: przedział półotwarty i symetryczny, bufory, zapis w UTC, godziny nieistniejące i podwójne przy zmianie czasu (przypadki z data providerów) |
| `ScreeningAdminServiceTest` | 10 | godzina lokalna i cennik, kolizja z listą w czasie kina, styk / inna sala / odwołany bez kolizji, ta sama reguła w constraincie, wiersz spoza serwisu → 409 zamiast 500, zmiana i blokady, odwołanie |
| `RepertoireDayBoundaryTest` | 1 | granice dnia repertuaru w strefie kina (regresja pułapki BN) |
| `ScreeningPanelTest` | 8 | dostęp administratora i obsługi, siatka w dniach lokalnych, ceny w zł, komunikaty przy polach, zmiana sali, blokada edycji przy sprzedaży, odwołanie, `#[Locked]` |
| `RepertoireCopyServiceTest` | 6 | godziny lokalne i ceny przez zmianę czasu, jeden problem blokuje całość, drugie kopiowanie niczego nie tworzy, podgląd bez zmian, reguły ręcznego planowania, walidacja dni |
| `RepertoireCopyPanelTest` | 5 | dostęp i link z siatki, podgląd i kopiowanie, raport problemów blokuje kopiowanie, zmiana po podglądzie wykryta przy kopiowaniu, uprawnienia |
| `BookingPanelTest` | 7 | zakres obsługi z podrobionym filtrem i maskowanie e-maili, filtry z dniem lokalnym, liczba zapytań niezależna od liczby rezerwacji, szczegóły z końcówką płatności, plan sali, uprawnienia |
| `SeatLockOrderingTest` | 3 | jedna kolejność blokad `seat_locks` na logu zapytań we wszystkich ścieżkach |
| `AdminBookingCancellationTest` | 12 | zwrot, zwolnienie autoryzacji, pending bez płatności, awaria operatora i ponowienie, capture wygrywający z void, `charge_already_refunded`, webhooki po anulowaniu, blokady i powód, kolejność blokad |
| `BookingCancellationPanelTest` | 3 | formularz tylko dla administratora, walidacja powodu, podpowiedź blokady, API klienta bez powodu |
| `PanelBroadcastingAuthTest` | 4 | podpisy feedu dla administratora i obsługi, błędy w JSON-ie dla żądań formularzem, limiter |
| `DashboardTest` | 7 | doba w strefie każdego kina, definicje brutto/zwroty/netto, doba 25-godzinna, obłożenie, top filmów, feed z bazy, skrypty przed Livewire |
| `ArticleApiTest` | 5 | tylko widoczne, zaplanowany pojawia się bez inwalidacji, cache i inwalidacja, ROLLBACK, 404 bez nowych kluczy |
| `ArticleManagementTest` | 5 | dostęp, premiera w strefie sieci, stały slug, godzina nieistniejąca, reguły serwisu i CHECK-i w bazie |
| `ArticleMarkdownTest` | 11 | formatowanie i 10 przypadków złośliwego wejścia |
| Etapy 1–6 | 208 | bez zmian w kontraktach API |
| **Razem** | **420** | |

Bloki E–M przeszły też **testy mutacyjne**: celowo psuty kod (np. usunięty strażnik
webhooka, brak blokady biletów, doba liczona jako 24 h, szkice w API) musiał wywrócić
testy. Wszystkie mutacje z wpływem na zachowanie zostały wykryte.

### Etap 7 — weryfikacja na żywo

Poza testami automatycznymi. Przeglądarkę i Reverb sprawdzałem w środowisku lustrzanym
(ten sam kod, PostgreSQL 16, Redis, nginx, Reverb), Stripe'a i testy dymne — na docelowym
środowisku Docker Compose.

- **Przeglądarka (Playwright)**: logowanie, edytor układu sali, formularz filmu z plakatem,
  siatka tygodnia z kolizją, kopiowanie dnia, lista rezerwacji i plan sali (administrator
  i obsługa), anulowanie z potwierdzeniem, formularz artykułu z podglądem — skrypt wklejony
  do treści się nie wykonał.
- **Feed na żywo przez nginx i Reverb** w dwóch przeglądarkach naraz: administrator dostał
  zdarzenia z Warszawy i Krakowa, obsługa Warszawy tylko swoje; zatrzymanie Reverba →
  status „łączenie…”, start → „połączono”, odtworzenie feedu i kolejne zdarzenia.
  Na docelowym środowisku pulpit w zwykłej przeglądarce pokazał „Na żywo: połączono”.
- **Stripe w trybie testowym przez Stripe CLI**: karta testowa „pm_card_visa” → webhook → bilety →
  capture → anulowanie w panelu → **dokładnie jeden zwrot na pełną kwotę** w Stripe, rezerwacja
  `refunded`, bilety anulowane; mail w Mailpit z numerem rezerwacji i informacją o zwrocie,
  bez powodu. Płatność rozpoczęta i niepotwierdzona → anulowanie → płatność `canceled`
  w Stripe, rezerwacja `cancelled`. Zero zaległych rozliczeń, wpisy komendy w logu schedulera.
- **Testy dymne przez nginx** po każdym bloku: 419 bez tokenu CSRF, limity logowania,
  podpisy kanałów i 403 dla obcego kina, plan zapytań po nowych indeksach (`EXPLAIN`),
  klucze „catalog:…” w Redisie i odpowiedź z cache po zmianie „za plecami” serwisu,
  dokumentacja Scramble z nowymi endpointami i bez tras panelu, blokada `FOR KEY SHARE`
  przy `INSERT` na PostgreSQL, deadlock przy przeciwnej kolejności blokad.

### Etap 7 — znane ograniczenia i co dalej

- **Lista artykułów w cache rośnie z liczbą artykułów.** Przy tysiącach — klucze per
  strona z TTL ograniczonym najbliższą publikacją.
- **Plan sali w panelu odświeża się co 10 s**, a nie przez kanał seansu — kanał odrzuca
  seanse rozpoczęte, a panel pokazuje także te.
- **Feed pulpitu to ostatnie zmiany, nie pełna historia zdarzeń** — po przerwie połączenia
  odtwarzamy stan rezerwacji z bazy, a nie każde przejście.
- **Zwrot uznajemy za zakończony po przyjęciu przez Stripe** (`pending` albo `succeeded`);
  zwrot, który Stripe odrzuci później (zdarzenie „refund.failed”), wymaga obsługi webhooka.
- **Osierocone pliki plakatów** po awarii między zapisem pliku a COMMIT — nieszkodliwe,
  sprzątanie komendą w Etapie 10.
- **Data publikacji artykułów w jednej strefie sieci** (Europe/Warsaw).
- **Porządki na Etap 10:** zdublowane wpisy w `.env.example` (`SCREENING_ADS_MINUTES`,
  martwe `SCREENING_CLEANUP_BUFFER`), `CACHE_STORE` i `SESSION_DRIVER` z wartościami
  `database` w `.env.example`, `APP_NAME` (prefiksy kluczy w Redisie), zaufane proxy
  przy TLS, niespójne wartości `age_rating` w `MovieFactory`, skrypt uruchamiający sondę
  WebSocket i skrypt zgodności README w CI.
