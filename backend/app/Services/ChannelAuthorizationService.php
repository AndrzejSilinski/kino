<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ChannelAccessDeniedException;
use App\Exceptions\RealtimeUnavailableException;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Screening;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Autoryzacja subskrypcji prywatnych kanałów WebSocket (Etap 6).
 *
 * DLACZEGO NIE Broadcast::routes() + routes/channels.php:
 * PusherBroadcaster::auth() odrzuca kanał private-* kodem 403, zanim
 * zapyta callback kanału, jeśli żądanie nie ma zalogowanego użytkownika.
 * Plan sali ma działać na żywo także dla kupującego bez konta, więc
 * standardowa ścieżka odpada. W zamian: jawna mapa nazwa kanału -> zasób
 * -> Policy, a podpis zlecamy tej samej bibliotece, której używa framework.
 *
 * KOLEJNOŚĆ: najpierw decyzja o dostępie, dopiero potem podpis. Odmowa
 * nie zależy od konfiguracji broadcastera, więc 403 nie zamienia się w 503.
 */
final class ChannelAuthorizationService
{
    private const SCREENING = '/\Aprivate-screenings\.(\d{1,18})\z/';

    private const BOOKING = '/\Aprivate-bookings\.([0-9A-Z]{26})\z/';

    private const CINEMA_SALES = '/\Aprivate-cinemas\.(\d{1,18})\.sales\z/';

    private const ALL_SALES = 'private-sales';

    public function __construct(
        private readonly Gate $gate,
        private readonly BroadcastManager $broadcast,
    ) {}

    /**
     * Zwraca odpowiedź, której oczekuje klient Pushera: {"auth": "klucz:podpis"}.
     *
     * @return array<string, string>
     *
     * @throws ChannelAccessDeniedException gdy kanał jest nieznany albo dostęp zabroniony
     * @throws RealtimeUnavailableException gdy broadcaster nie potrafi podpisywać
     */
    public function authorize(string $channel, string $socketId, ?User $user): array
    {
        if (! $this->allows($channel, $user)) {
            throw new ChannelAccessDeniedException;
        }

        $broadcaster = $this->broadcast->connection();

        if (! $broadcaster instanceof PusherBroadcaster) {
            // Błąd konfiguracji, a nie zachowanie klienta — dlatego log.
            // Wyjątki domenowe są w dontReport (decyzja 21).
            Log::warning('Autoryzacja kanału niemożliwa: broadcaster nie obsługuje podpisów.', [
                'broadcaster' => $broadcaster::class,
            ]);

            throw new RealtimeUnavailableException;
        }

        // HMAC-SHA256(secret, "socket_id:channel") liczy klient Pushera.
        // Reverb zna ten sam sekret i sprawdza podpis przy pusher:subscribe.
        return json_decode(
            $broadcaster->getPusher()->authorizeChannel($channel, $socketId),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Nazwa kanału -> zasób -> Policy. Brak dopasowania oznacza odmowę:
     * nowy kanał trzeba tu świadomie dopisać, nie ma "domyślnie wpuść".
     *
     * Gate::forUser($user), a nie domyślny strażnik: użytkownik pochodzi
     * z tokenu Sanctum odczytanego w kontrolerze, a dla gościa jest null.
     */
    private function allows(string $channel, ?User $user): bool
    {
        $gate = $this->gate->forUser($user);

        if (preg_match(self::SCREENING, $channel, $match) === 1) {
            $screening = Screening::query()->find((int) $match[1]);

            return $screening !== null && $gate->allows('watchSeatMap', $screening);
        }

        if (preg_match(self::BOOKING, $channel, $match) === 1) {
            $booking = Booking::query()->where('reference', $match[1])->first();

            return $booking !== null && $gate->allows('listen', $booking);
        }

        if (preg_match(self::CINEMA_SALES, $channel, $match) === 1) {
            $cinema = Cinema::query()->find((int) $match[1]);

            return $cinema !== null && $gate->allows('viewSales', $cinema);
        }

        if ($channel === self::ALL_SALES) {
            return $gate->allows('viewAnySales', Cinema::class);
        }

        return false;
    }
}
