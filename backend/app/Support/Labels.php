<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\LanguageVersion;
use App\Enums\ProjectionType;
use App\Enums\TicketStatus;

/**
 * Etykiety enumów po polsku.
 *
 * Kontrakt API: zawsze surowa wartość enuma DLA MASZYNY plus osobne
 * pole *_label DLA CZŁOWIEKA. Vue i Flutter nie utrzymują własnych
 * słowników, a dodanie nowego przypadku to zmiana w jednym pliku,
 * nie w trzech repozytoriach.
 *
 * match bez gałęzi default jest celowy: gdy ktoś doda nowy przypadek
 * do enuma i zapomni o etykiecie, PHP rzuci UnhandledMatchError
 * przy pierwszym użyciu. Wolę głośny błąd niż ciche "null" w interfejsie.
 */
final class Labels
{
    public static function projectionType(ProjectionType $type): string
    {
        return match ($type) {
            ProjectionType::TwoD => '2D',
            ProjectionType::ThreeD => '3D',
            ProjectionType::Imax => 'IMAX',
        };
    }

    public static function languageVersion(LanguageVersion $version): string
    {
        return match ($version) {
            LanguageVersion::Original => 'Wersja oryginalna',
            LanguageVersion::Subtitles => 'Napisy polskie',
            LanguageVersion::Dubbing => 'Dubbing polski',
        };
    }

    public static function bookingStatus(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Pending => 'Oczekuje na płatność',
            BookingStatus::Paid => 'Opłacona',
            BookingStatus::Cancelled => 'Anulowana',
            BookingStatus::Expired => 'Wygasła',
            BookingStatus::Refunded => 'Zwrócona',
        };
    }

    /**
     * Powód nieudanego zwrotu (Etap 10, blok C) — kody Stripe'a z Refund::FAILURE_REASON_*.
     * Nieznany kod pokazujemy dosłownie: operator może dodać nowy, a panel nie może wtedy milczeć.
     */
    public static function refundFailureReason(?string $code): string
    {
        return match ($code) {
            'lost_or_stolen_card' => 'karta zgłoszona jako zgubiona lub skradziona',
            'expired_or_canceled_card' => 'karta wygasła lub została zamknięta',
            'charge_for_pending_refund_disputed' => 'płatność jest przedmiotem sporu (chargeback)',
            'insufficient_funds' => 'brak środków na koncie kina u operatora płatności',
            'declined' => 'bank klienta odrzucił zwrot',
            'merchant_request' => 'zwrot anulowany na żądanie kina',
            null, 'unknown' => 'operator nie podał przyczyny',
            default => 'kod operatora: '.$code,
        };
    }

    public static function ticketStatus(TicketStatus $status): string
    {
        return match ($status) {
            TicketStatus::Valid => 'Ważny',
            TicketStatus::Used => 'Wykorzystany',
            TicketStatus::Cancelled => 'Anulowany',
        };
    }
}
