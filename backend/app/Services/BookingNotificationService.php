<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ScreeningStatus;
use App\Jobs\GenerateBookingTicketsPdf;
use App\Models\Booking;
use App\Notifications\ScreeningReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Powiadomienia o rezerwacjach wysyłane z harmonogramu (Blok G).
 *
 * PONOWIENIE ZGUBIONYCH POTWIERDZEŃ (decyzje 73 i 87): słuchacz płatności
 * łapie awarię kolejki i nie przerywa webhooka, więc potwierdzenie może nie
 * trafić do kolejki wcale. Tu podnosimy takie przypadki: opłacone, bez
 * confirmation_sent_at, w oknie od retryAfterMinutes do retryWindowMinutes
 * po płatności. Zapytanie korzysta z indeksu bookings_confirmation_pending.
 * Zadanie PDF jest idempotentne i unikalne, więc ponowienie nie zdubluje maila.
 *
 * PRZYPOMNIENIA O SEANSIE (decyzja 89): najpierw atomowo "zajmujemy"
 * rezerwacje jednym UPDATE ... RETURNING, dopiero potem kolejkujemy maile.
 * Dwa równoległe przebiegi schedulera nie wyślą dwóch przypomnień, bo drugi
 * UPDATE nie znajdzie już wierszy z reminder_sent_at IS NULL. Cena: jeśli
 * kolejka padnie między zajęciem a wysłaniem, przypomnienie przepadnie
 * ("najwyżej raz"). Dla przypomnienia to właściwy wybór — w przeciwieństwie
 * do potwierdzenia z biletami, które ponawiamy ("co najmniej raz").
 *
 * Progi przychodzą jako argumenty (komendy czytają je z config/tickets.php),
 * więc serwis nie potrzebuje wiązania w providerze, a test podaje własne.
 */
final class BookingNotificationService
{
    /** @return list<string> referencje rezerwacji, dla których ponowiono potwierdzenie */
    public function resendMissingConfirmations(
        int $retryAfterMinutes,
        int $retryWindowMinutes,
        bool $ignoreWindow = false,
        int $limit = 200,
        ?CarbonImmutable $now = null,
    ): array {
        $now ??= CarbonImmutable::now();

        $bookings = Booking::query()
            ->where('status', BookingStatus::Paid)
            ->whereNull('confirmation_sent_at')
            ->where('paid_at', '<=', $now->subMinutes($retryAfterMinutes))
            ->when(! $ignoreWindow, fn ($query) => $query->where('paid_at', '>', $now->subMinutes($retryWindowMinutes)))
            ->orderBy('paid_at')
            ->limit($limit)
            ->get(['id', 'reference']);

        foreach ($bookings as $booking) {
            GenerateBookingTicketsPdf::dispatch($booking->id);
        }

        return $bookings->pluck('reference')->all();
    }

    /**
     * Przypomnienia dla rezerwacji, których seans zaczyna się w ciągu
     * $minutesBefore minut.
     *
     * Pomijamy rezerwacje opłacone już PO otwarciu okna przypomnień: taki
     * klient przed chwilą dostał mail z biletami i nie potrzebuje drugiego.
     * Seans musi być zaplanowany i jeszcze się nie zacząć — po przerwie
     * schedulera przypomnienie przyjdzie później, ale nigdy po starcie.
     *
     * Bindowanie pozycyjne (?), a nie nazwane: PDO dla PostgreSQL bez
     * emulacji zapytań nie pozwala użyć tej samej nazwy parametru dwa razy.
     *
     * @return list<string> referencje rezerwacji, którym wysłano przypomnienie
     */
    public function sendDueReminders(int $minutesBefore, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $claimed = DB::select(
            <<<'SQL'
                UPDATE bookings AS b
                SET reminder_sent_at = CAST(? AS timestamptz),
                    updated_at = CAST(? AS timestamp)
                FROM screenings AS s
                WHERE s.id = b.screening_id
                  AND b.status = ?
                  AND b.reminder_sent_at IS NULL
                  AND s.status = ?
                  AND s.starts_at > CAST(? AS timestamptz)
                  AND s.starts_at <= CAST(? AS timestamptz)
                  AND b.paid_at <= s.starts_at - CAST(? AS integer) * INTERVAL '1 minute'
                RETURNING b.id
                SQL,
            [
                $now->toIso8601String(),
                $now->utc()->format('Y-m-d H:i:s'),
                BookingStatus::Paid->value,
                ScreeningStatus::Scheduled->value,
                $now->toIso8601String(),
                $now->addMinutes($minutesBefore)->toIso8601String(),
                $minutesBefore,
            ],
        );

        $ids = array_map(static fn (object $row): int => (int) $row->id, $claimed);

        if ($ids === []) {
            return [];
        }

        $bookings = Booking::query()->with('user')->whereIn('id', $ids)->orderBy('id')->get();

        foreach ($bookings as $booking) {
            try {
                $booking->user->notify(new ScreeningReminder($booking->id));
            } catch (Throwable $e) {
                // Rezerwacja jest już "zajęta" (reminder_sent_at ustawione), więc
                // tego przypomnienia nie ponowimy. Raportujemy i idziemy dalej,
                // żeby awaria jednej wysyłki nie zatrzymała pozostałych.
                report($e);
            }
        }

        return $bookings->pluck('reference')->all();
    }
}
