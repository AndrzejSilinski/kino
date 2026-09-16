<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * ZASADY WSPÓLNE DLA CAŁEGO HARMONOGRAMU
 *
 * appendOutputTo($output) — pułapka V. schedule:run przekierowuje stdout
 *   i stderr każdej komendy do /dev/null, a nasze logi idą na stderr
 *   (decyzja 22). Kontener scheduler ustawia SCHEDULE_OUTPUT=/proc/1/fd/2,
 *   więc wyjście i logi komend trafiają do `docker logs cinema_scheduler`.
 *   Poza tym kontenerem zostaje /dev/null: ręczne schedule:run w kontenerze
 *   php nie może pisać do stderr php-fpm (należy do roota), a nieudane
 *   przekierowanie w powłoce nie uruchomiłoby komendy wcale.
 *
 * onOneServer() — przy kilku instancjach schedulera (np. dwa serwery na
 *   produkcji) komenda wykona się raz; blokada siedzi w cache Redis.
 *
 * withoutOverlapping() — przebieg, który się przeciągnął, nie zostanie
 *   zdublowany przez następny.
 *
 * runInBackground() — komenda nie blokuje pozostałych zadań w tej minucie.
 */
$output = (string) config('cinema.schedule_output');

/*
 * Czyszczenie wygasłych blokad miejsc (Etap 2).
 *
 * everyMinute() — TTL blokady to 10 minut, więc minuta opóźnienia jest
 * niezauważalna dla klienta, a koszt zapytania znikomy (indeks częściowy
 * seat_locks_expiry_sweep obsługuje ten WHERE bez skanowania tabeli).
 */
Schedule::command('cinema:seat-locks:sweep')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo($output);

/*
 * Wygaszanie nieopłaconych rezerwacji (Etap 4).
 *
 * everyMinute() — okno płatności ma 10 minut, a zapytanie korzysta z indeksu
 * bookings_status_expires_at_index. Przebieg woła Stripe'a, więc może się
 * przeciągnąć — stąd withoutOverlapping().
 *
 * Kolejność wobec cinema:seat-locks:sweep nie ma znaczenia dla poprawności:
 * o miejscu decyduje wiersz w bazie, nie zegar (decyzja 35).
 */
Schedule::command('cinema:bookings:expire')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo($output);

/*
 * Oznaczanie zakończonych seansów (Etap 5).
 *
 * everyFiveMinutes() — status finished służy raportom, a nie sprzedaży,
 * więc pięć minut opóźnienia nikomu nie przeszkadza.
 */
Schedule::command('cinema:screenings:finish')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo($output);

/*
 * Ponawianie zgubionych potwierdzeń zakupu (Etap 5, decyzja 73).
 *
 * everyFifteenMinutes() — próg "zgubione" to 15 minut od płatności, więc
 * częstsze uruchamianie niczego by nie przyspieszyło. W oknie 120 minut
 * daje to najwyżej kilka automatycznych ponowień na rezerwację.
 */
Schedule::command('cinema:bookings:resend-confirmations')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo($output);

/*
 * Przypomnienia o seansie (Etap 5, decyzja 89).
 *
 * everyFiveMinutes() — przypomnienie przychodzi między 120 a 115 minutą
 * przed seansem. Podwójne wysłanie wyklucza atomowe "zajęcie" rezerwacji
 * w bazie (UPDATE ... RETURNING), a nie sama częstotliwość.
 */
Schedule::command('cinema:screenings:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo($output);

/*
 * Ponawianie rozliczeń płatności rezerwacji anulowanych przez administratora
 * (Etap 7, blok K).
 *
 * everyFiveMinutes() — panel rozlicza płatność od razu; tu trafiają tylko
 * przypadki, w których operator był niedostępny albo płatność była w toku.
 * Przebieg woła Stripe'a, więc withoutOverlapping(). Równoległe wywołanie
 * z panelem jest bezpieczne: te same klucze idempotencji i FOR UPDATE w kroku 3.
 */
Schedule::command('cinema:bookings:retry-refunds')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo($output);

/*
 * Usuwanie wygasłych tokenów Sanctum (Etap 8, blok D).
 *
 * dailyAt('03:30') — wygasły token jest odrzucany przy każdym żądaniu już teraz
 * (sanctum.expiration); komenda tylko sprząta tabelę personal_access_tokens, więc
 * raz na dobę, poza godzinami sprzedaży, wystarczy. --hours=24: rekord znika dobę
 * po wygaśnięciu tokenu.
 */
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo($output);
