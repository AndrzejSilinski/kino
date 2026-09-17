<?php

use App\Http\Controllers\Api\V1\AccountAvatarController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingPaymentController;
use App\Http\Controllers\Api\V1\BookingTicketsController;
use App\Http\Controllers\Api\V1\BroadcastingAuthController;
use App\Http\Controllers\Api\V1\CinemaController;
use App\Http\Controllers\Api\V1\ClientConfigController;
use App\Http\Controllers\Api\V1\MovieController;
use App\Http\Controllers\Api\V1\NotificationSettingsController;
use App\Http\Controllers\Api\V1\ScreeningController;
use App\Http\Controllers\Api\V1\SeatLockController;
use App\Http\Controllers\Api\V1\SeatMapController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\TicketValidationController;
use App\Http\Middleware\ResolveBookingSession;
use App\Http\Middleware\VerifyStripeSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Trasy API — wersja 1
|--------------------------------------------------------------------------
| Prefiks /api/v1 nadaje bootstrap/app.php (parametr apiPrefix).
|
| Układ pliku odpowiada ścieżce zakupowej klienta:
|   1. diagnostyka
|   2. konto
|   3. katalog
|   4. koszyk          <- ten krok
|   5. rezerwacje      (krok 3.7)
*/

// ─── 1. Diagnostyka ──────────────────────────────────────────────────────
Route::get('/ping', function () {
    return response()->json([
        'data' => [
            'status'  => 'ok',
            'service' => 'cinema-api',
            'version' => 'v1',
            'time'    => now()->toIso8601String(),
        ],
    ]);
})->name('api.ping');

// Etap 8, blok C: konfiguracja aplikacji klienckich w czasie działania (klucz publiczny
// Reverba, limity koszyka). Tylko wartości jawne; bez logowania i bez sesji zakupowej.
Route::get('/client-config', ClientConfigController::class)
    ->name('api.client-config');

// ─── 2. Konto ────────────────────────────────────────────────────────────
Route::prefix('auth')->name('api.auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register')
        ->name('register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
    });
});

// Profil, hasło, avatar i ustawienia powiadomień (Etap 8, blok I). Wszystko działa na
// zalogowanym użytkowniku — bez identyfikatora konta w adresie. Odczyt profilu: GET /auth/me.
// Limit "account" per użytkownik na zmiany; zmiana hasła ma własny, ciaśniejszy limit,
// bo pole current_password pozwalałoby zgadywać hasło skradzionym tokenem.
Route::middleware('auth:sanctum')
    ->prefix('account')
    ->name('api.account.')
    ->group(function () {
        Route::get('/notifications', [NotificationSettingsController::class, 'show'])->name('notifications.show');

        Route::middleware('throttle:account')->group(function () {
            Route::patch('/profile', [AccountController::class, 'updateProfile'])->name('profile.update');
            Route::put('/password', [AccountController::class, 'changePassword'])
                ->middleware('throttle:password-change')
                ->name('password.update');
            Route::post('/avatar', [AccountAvatarController::class, 'store'])->name('avatar.store');
            Route::delete('/avatar', [AccountAvatarController::class, 'destroy'])->name('avatar.destroy');
            Route::patch('/notifications', [NotificationSettingsController::class, 'update'])->name('notifications.update');
        });
    });

// ─── 3. Katalog (publiczny) ──────────────────────────────────────────────
Route::get('/cinemas', [CinemaController::class, 'index'])
    ->name('api.cinemas.index');

Route::get('/cinemas/{cinema}', [CinemaController::class, 'show'])
    ->name('api.cinemas.show');

Route::get('/cinemas/{cinema}/screening-dates', [ScreeningController::class, 'dates'])
    ->name('api.cinemas.screening-dates');

Route::get('/cinemas/{cinema}/screenings', [ScreeningController::class, 'index'])
    ->name('api.cinemas.screenings');

Route::get('/screenings/{screening}', [ScreeningController::class, 'show'])
    ->name('api.screenings.show');

// Etap 7, blok F: lista filmów w repertuarze sieci (wymóg 1.7 — cache listy filmów).
Route::get('/movies', [MovieController::class, 'index'])
    ->name('api.movies.index');

// Etap 7, blok M: moduł informacyjny (wymóg 2.4) — opublikowane artykuły z cache.
// Slug w formacie Str::slug; inny zapis nie trafia nawet do kontrolera (404).
Route::get('/articles', [ArticleController::class, 'index'])
    ->name('api.articles.index');

Route::get('/articles/{slug}', [ArticleController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('api.articles.show');

// ─── 4. Koszyk ───────────────────────────────────────────────────────────
// Plan sali czyta sesję, żeby odróżnić blokady własne od cudzych, ale
// NIE dostaje limitu 'seat-locks'. Powód: po zerwaniu WebSocketa klient
// pobiera pełny stan sali przez REST (wymóg 1.3 zadania), a przy słabym
// łączu takich pobrań bywa dużo. Odcięcie klienta w tym momencie
// zostawiłoby go z nieaktualnym planem.
Route::middleware(ResolveBookingSession::class)->group(function () {
    Route::get('/screenings/{screening}/seat-map', SeatMapController::class)
        ->name('api.screenings.seat-map');
});

// Operacje zmieniające stan blokad. Limit 30/min kluczowany SESJĄ
// ZAKUPOWĄ, nie adresem IP — klienci w galerii handlowej dzielą jedno
// publiczne IP za NAT-em i limit per IP odciąłby całą salę podczas
// premiery (patrz AppServiceProvider).
Route::middleware([ResolveBookingSession::class, 'throttle:seat-locks'])
    ->prefix('screenings/{screening}/seat-locks')
    ->name('api.screenings.seat-locks.')
    ->group(function () {
        // Moje blokady + wycena koszyka + czas do wygaśnięcia.
        Route::get('/', [SeatLockController::class, 'index'])->name('index');

        // Zablokuj miejsca. All-or-nothing, konflikt => 409.
        Route::post('/', [SeatLockController::class, 'store'])->name('store');

        // Porzucenie całego koszyka ("Wyczyść wybór"); 200 z pustym koszykiem (Etap 8, blok F).
        Route::delete('/', [SeatLockController::class, 'destroyAll'])->name('destroy-all');

        // Odkliknięcie jednego miejsca. Idempotentne; 200 z aktualnym koszykiem (Etap 8, blok F).
        Route::delete('/{seat}', [SeatLockController::class, 'destroy'])->name('destroy');
    });

// ─── 5. Rezerwacje (wymagają zalogowania) ────────────────────────────────
// Klucz trasy to reference (ULID), nie sekwencyjne id — patrz
// Booking::getRouteKeyName(). Sekwencyjny identyfikator w adresie
// pozwalałby skanować cudze rezerwacje, a samo 403 potwierdzałoby
// wtedy, że pod danym numerem coś istnieje.
Route::middleware('auth:sanctum')
    ->prefix('bookings')
    ->name('api.bookings.')
    ->group(function () {
        Route::get('/', [BookingController::class, 'index'])->name('index');
        Route::get('/{booking}', [BookingController::class, 'show'])->name('show');

        // Rezygnacja z rozpoczętej płatności (Etap 8, blok H1): miejsca wracają do sprzedaży
        // od razu, a nie po oknie płatności. DELETE na podzasobie "payment", bo usuwamy
        // rozpoczętą płatność, a nie rezerwację — ta zostaje w historii jako anulowana.
        Route::delete('/{booking}/payment', [BookingPaymentController::class, 'destroy'])
            ->name('payment.destroy');

        // Bilety rezerwacji (Etap 5, decyzje 78 i 79). scopeBindings() szuka
        // {ticket} wyłącznie wśród $booking->tickets(): id biletu z innej
        // rezerwacji daje 404, zanim wykona się kontroler.
        Route::get('/{booking}/tickets/pdf', [BookingTicketsController::class, 'pdf'])
            ->middleware('throttle:ticket-downloads')
            ->name('tickets.pdf');
        Route::get('/{booking}/tickets/{ticket}/qr', [BookingTicketsController::class, 'qr'])
            ->middleware('throttle:ticket-downloads')
            ->scopeBindings()
            ->name('tickets.qr');
    });

// ─── 6. Webhook płatności ────────────────────────────────────────────────
// Bez auth:sanctum i bez CSRF: Stripe nie ma ani tokenu, ani ciasteczka.
// Jedyną barierą jest podpis sprawdzany w VerifyStripeSignature.
//
// Bez limitu żądań. Przy premierze Stripe potrafi wysłać serię zdarzeń,
// a odpowiedź 429 oznaczałaby dla niego nieudane dostarczenie i ponowienie
// za jakiś czas — czyli klienta czekającego na bilety bez powodu.
// Endpoint i tak jest chroniony kryptograficznie, nie limitem.
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->middleware(VerifyStripeSignature::class)
    ->withoutMiddleware('throttle:api')
    ->name('api.webhooks.stripe');

// ─── 7. Checkout ─────────────────────────────────────────────────────────
// Wymaga zalogowania (bookings.user_id jest NOT NULL — nie ma zakupu jako
// gość) ORAZ sesji zakupowej, bo to ona wyznacza koszyk.
//
// Limit ten sam co przy blokowaniu miejsc: kluczowany sesją zakupową,
// nie adresem IP. Checkout jest operacją pieniężną, więc nie chcemy, żeby
// skrypt mógł zasypać Stripe'a tworzeniem płatności.
Route::middleware(['auth:sanctum', ResolveBookingSession::class, 'throttle:seat-locks'])
    ->post('/screenings/{screening}/booking', CheckoutController::class)
    ->name('api.screenings.checkout');

// ─── 8. Walidacja biletów (obsługa kina) ────────────────────────────────
// Skaner przy wejściu na salę. Kto może skanować na danym seansie,
// rozstrzyga ScreeningPolicy (admin wszędzie, obsługa w swoim kinie).
// Limit per pracownik, a nie per IP: kilka bramek w jednym kinie wychodzi
// do internetu z jednego adresu.
Route::middleware(['auth:sanctum', 'throttle:ticket-validation'])
    ->post('/tickets/validate', TicketValidationController::class)
    ->name('api.tickets.validate');

// ─── 9. WebSocket: autoryzacja kanałów prywatnych (Etap 6) ─────────────
// Bez auth:sanctum: kanał planu sali jest dostępny także dla kupującego
// bez konta. Kto może słuchać którego kanału, rozstrzyga
// ChannelAuthorizationService przez Policies. Limit w RealtimeServiceProvider.
Route::post('/broadcasting/auth', BroadcastingAuthController::class)
    ->middleware('throttle:broadcasting-auth')
    ->name('api.broadcasting.auth');
