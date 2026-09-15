<?php

namespace App\Http\Resources\V1;

use App\Models\Screening;
use App\Models\ScreeningPrice;
use App\Support\Labels;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pełne dane seansu — ekran szczegółów i nagłówek planu sali.
 *
 * @mixin Screening
 */
class ScreeningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at->copy()->setTimezone($this->timezone())->toIso8601String(),
            'ends_at' => $this->ends_at->copy()->setTimezone($this->timezone())->toIso8601String(),
            'projection_type' => $this->projection_type->value,
            'projection_type_label' => Labels::projectionType($this->projection_type),
            'language_version' => $this->language_version->value,
            'language_version_label' => Labels::languageVersion($this->language_version),
            'status' => $this->status->value,
            // isBookable() z enuma mówi tylko o statusie. Seans zaplanowany,
            // ale już rozpoczęty, też nie przyjmuje rezerwacji.
            'is_bookable' => $this->status->isBookable() && $this->starts_at->isFuture(),
            'movie' => new MovieResource($this->whenLoaded('movie')),
            'hall' => new HallResource($this->whenLoaded('hall')),
            'prices' => $this->whenLoaded('prices', fn () => $this->prices
                ->map(fn (ScreeningPrice $price): array => [
                    'category' => [
                        'id' => $price->priceCategory?->id,
                        'slug' => $price->priceCategory?->slug,
                        'name' => $price->priceCategory?->name,
                        'color' => $price->priceCategory?->color,
                    ],
                    'price' => Money::minor($price->price)->toArray(),
                ])
                ->values()),
        ];
    }

    /**
     * Strefa czasowa kina, w której wyświetlamy godziny seansu.
     *
     * Sprawdzamy relacje jawnie przez relationLoaded(), zamiast pisać
     * $this->hall?->cinema?->timezone. Przy włączonym preventLazyLoading
     * sięgnięcie po niezaładowaną relację rzuca wyjątkiem, a nie zwraca
     * null — operator ?-> by tu nie pomógł, tylko ukrył problem.
     */
    private function timezone(): string
    {
        if ($this->relationLoaded('hall') && $this->hall->relationLoaded('cinema')) {
            return $this->hall->cinema->timezone;
        }

        return (string) config('app.timezone');
    }
}
