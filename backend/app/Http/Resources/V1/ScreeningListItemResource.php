<?php

namespace App\Http\Resources\V1;

use App\Models\Screening;
use App\Support\Labels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pozycja repertuaru dnia — karta seansu na liście.
 *
 * Strefa czasowa pochodzi z relacji hall.cinema, którą RepertoireService
 * ładuje jednym zapytaniem na całą stronę. Dzięki temu zasób ma zwykły
 * konstruktor i da się go użyć przez ::collection(), co generuje
 * standardowe links i meta paginacji.
 *
 * @mixin Screening
 */
class ScreeningListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $capacity = (int) ($this->hall->active_seats_count ?? 0);
        $taken = (int) $this->sold_seats_count + (int) $this->held_seats_count;

        // Wyprzedany = sprzedane + aktywnie blokowane >= pojemność sali.
        $isSoldOut = $capacity > 0 && $taken >= $capacity;
        $hasStarted = $this->starts_at->isPast();
        $timezone = $this->hall->cinema->timezone;

        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at->copy()->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->copy()->setTimezone($timezone)->toIso8601String(),
            'projection_type' => $this->projection_type->value,
            'projection_type_label' => Labels::projectionType($this->projection_type),
            'language_version' => $this->language_version->value,
            'language_version_label' => Labels::languageVersion($this->language_version),
            'hall' => [
                'id' => $this->hall->id,
                'name' => $this->hall->name,
            ],
            'movie' => [
                'id' => $this->movie->id,
                'slug' => $this->movie->slug,
                'title' => $this->movie->title,
                'duration_minutes' => $this->movie->duration_minutes,
                'age_rating' => $this->movie->age_rating,
                'poster_url' => $this->movie->poster_path !== null
                    ? asset('storage/'.ltrim($this->movie->poster_path, '/'))
                    : null,
            ],
            'seats' => [
                'total' => $capacity,
                'taken' => $taken,
                'available' => max(0, $capacity - $taken),
            ],
            // Trzy osobne flagi zamiast jednej: frontend wyszarza inaczej
            // seans wyprzedany ("brak miejsc") niż rozpoczęty ("po czasie").
            'is_sold_out' => $isSoldOut,
            'has_started' => $hasStarted,
            'is_bookable' => ! $isSoldOut && ! $hasStarted,
        ];
    }
}
