<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Ticket;
use App\Support\Labels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wynik skanu dla aplikacji obsługi kina.
 *
 * Tylko to, czego potrzebuje bramkarz: miejsce, seans, stan biletu.
 * Bez kodu biletu, bez numeru rezerwacji i bez danych klienta — obsługa
 * nie ma powodu wiedzieć, kto kupił bilet, a skaner bywa urządzeniem
 * współdzielonym.
 *
 * @mixin Ticket
 */
final class TicketValidationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $screening = $this->screening;
        $timezone = $screening->hall->cinema->timezone;

        return [
            'status' => $this->status->value,
            'status_label' => Labels::ticketStatus($this->status),
            'validated_at' => $this->validated_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'seat' => [
                'row' => $this->seat->row_label,
                'number' => $this->seat->seat_number,
                'label' => $this->seat->label,
                'type' => $this->seat->type->value,
                'type_label' => $this->seat->type->label(),
            ],
            'screening' => [
                'id' => $screening->id,
                'movie_title' => $screening->movie->title,
                'hall' => $screening->hall->name,
                'starts_at' => $screening->starts_at->copy()->setTimezone($timezone)->toIso8601String(),
            ],
        ];
    }
}
