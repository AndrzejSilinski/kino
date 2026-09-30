<?php

declare(strict_types=1);

/*
 * Dane dla sondy WebSocket (Etap 10, blok B2). Uruchamiane przez run.sh w kontenerze php,
 * z kodem podanym na standardowe wejście (kontener widzi tylko backend/, a nie tools/):
 *
 *   docker compose exec -T php php -- prepare < tools/realtime-probe/sonda.php
 *   docker compose exec -T php php -- event <booking_id> < tools/realtime-probe/sonda.php
 *   docker compose exec -T php php -- cleanup < tools/realtime-probe/sonda.php
 *
 * prepare — seans w sprzedaży, rezerwacja techniczna klienta anna@cinema.test (od razu
 *           ANULOWANA: bez miejsc, bez płatności, niewidoczna dla sprzątania blokad), trzy tokeny
 *           Sanctum (właściciel, obcy klient, administrator). Wynik: JSON na standardowe wyjście,
 *           które run.sh zapisuje prosto do pliku z prawami 600 — tokeny nie trafiają na ekran.
 * event   — próbne zdarzenie tej rezerwacji przez prawdziwy RealtimeNotifier: kanał właściciela
 *           i feed sprzedaży administratora. Prawdziwy checkout tworzyłby płatność w Stripe.
 * cleanup — usuwa rezerwacje techniczne i tokeny sondy, także pozostałe po przerwanym przebiegu.
 *
 * Rozpoznajemy je po stałych TOKEN_NAME i REASON, nie po identyfikatorach z pliku: cleanup musi
 * zadziałać także wtedy, gdy prepare padło w połowie i pliku nie ma.
 */

use App\Enums\BookingStatus;
use App\Enums\ScreeningStatus;
use App\Models\Booking;
use App\Models\Screening;
use App\Models\User;
use App\Services\RealtimeNotifier;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Sanctum\PersonalAccessToken;

// Katalog backendu w kontenerze php; SONDA_BACKEND tylko do uruchomienia poza kontenerem.
$backend = getenv('SONDA_BACKEND') ?: '/var/www/html';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Funkcje tego pliku mają prefiks sonda_: PHP deklaruje funkcje globalne JUŻ przy kompilacji
// skryptu, przed require autoloadera, więc funkcja nazwana event() zajęłaby miejsce pomocnika
// Laravela event() (ten deklaruje się tylko "if (! function_exists('event'))"). RealtimeNotifier
// wołałby wtedy naszą funkcję z obiektem zdarzenia — TypeError i zero zdarzeń (pułapka EQ).
const TOKEN_NAME = 'sonda-realtime';
const REASON = 'Sonda WebSocket (tools/realtime-probe): rezerwacja techniczna, usuwana po przebiegu';
const OWNER = 'anna@cinema.test';
const STRANGER = 'piotr@cinema.test';
const ADMIN = 'admin@cinema.test';

function sonda_stop(string $message): never
{
    fwrite(STDERR, "STOP: {$message}\n");
    exit(1);
}

function sonda_cleanup(): void
{
    $bookings = Booking::query()->where('cancellation_reason', REASON)->delete();
    $tokens = PersonalAccessToken::query()->where('name', TOKEN_NAME)->delete();
    fwrite(STDERR, "sprzątanie: rezerwacje techniczne {$bookings}, tokeny {$tokens}\n");
}

function sonda_prepare(): void
{
    sonda_cleanup();

    $users = [];
    foreach (['owner' => OWNER, 'stranger' => STRANGER, 'admin' => ADMIN] as $role => $email) {
        $users[$role] = User::query()->where('email', $email)->first()
            ?? sonda_stop("brak użytkownika {$email} (dane z seedera: php artisan db:seed)");
    }

    // Kanał seansu autoryzuje tylko seans w sprzedaży (ScreeningPolicy::watchSeatMap).
    // Godzina zapasu: seans nie może zacząć się w trakcie przebiegu.
    $screening = Screening::query()
        ->with('hall')
        ->where('status', ScreeningStatus::Scheduled)
        ->where('starts_at', '>', now()->addHour())
        ->orderBy('starts_at')
        ->first()
        ?? sonda_stop('brak seansu w sprzedaży zaczynającego się później niż za godzinę');

    $booking = new Booking;
    $booking->forceFill([
        'user_id' => $users['owner']->id,
        'screening_id' => $screening->id,
        'status' => BookingStatus::Cancelled,
        'total_amount' => 0,
        'currency' => config('cinema.booking.currency', 'PLN'),
        'expires_at' => now(),
        'cancelled_at' => now(),
        'cancellation_reason' => REASON,
    ])->save();

    echo json_encode([
        'screening_id' => $screening->id,
        'cinema_id' => $screening->hall->cinema_id,
        'booking_id' => $booking->id,
        'booking_reference' => $booking->reference,
        'tokens' => array_map(static fn (User $user): string => $user->createToken(TOKEN_NAME)->plainTextToken, $users),
    ], JSON_THROW_ON_ERROR), "\n";
}

function sonda_event(int $bookingId): void
{
    Booking::query()->where('id', $bookingId)->where('cancellation_reason', REASON)->exists()
        || sonda_stop("rezerwacja {$bookingId} nie jest rezerwacją techniczną sondy");

    $sent = app(RealtimeNotifier::class)->bookingChanged($bookingId, BookingStatus::Cancelled);
    fwrite(STDERR, 'zdarzenie rezerwacji: '.($sent ? 'wysłane' : 'NIE wysłane (Reverb albo bezpiecznik)')."\n");
    exit($sent ? 0 : 1);
}

match ($argv[1] ?? '') {
    'prepare' => sonda_prepare(),
    'event' => sonda_event((int) ($argv[2] ?? 0)),
    'cleanup' => sonda_cleanup(),
    default => sonda_stop('użycie: prepare | event <booking_id> | cleanup'),
};
