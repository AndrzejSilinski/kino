<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Film na liście filmów (Etap 7, blok F) — bez opisu.
 *
 * Opis bywa długi, a lista służy do wyboru filmu; pełne dane są w szczegółach
 * seansu (MovieResource). Adres plakatu budowany tak samo jak w MovieResource.
 *
 * @mixin Movie
 */
class MovieListItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'original_title' => $this->original_title,
            'duration_minutes' => $this->duration_minutes,
            'age_rating' => $this->age_rating,
            'genres' => $this->genres ?? [],
            'premiere_date' => $this->premiere_date?->toDateString(),
            'poster_url' => $this->poster_path !== null
                ? asset('storage/'.ltrim($this->poster_path, '/'))
                : null,
        ];
    }
}
