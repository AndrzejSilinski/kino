<?php

namespace App\Http\Resources\V1;

use App\Models\Movie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Film — pełne dane, używane na ekranie szczegółów seansu.
 *
 * Lista repertuaru NIE używa tego zasobu: tam potrzeba tylko tytułu,
 * plakatu i czasu trwania, a ciągnięcie opisu dla dwudziestu seansów
 * dnia to kilkadziesiąt kilobajtów na każde odświeżenie ekranu.
 * Skrócony zestaw pól jest wbudowany w ScreeningListItemResource.
 *
 * @mixin Movie
 */
class MovieResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'original_title' => $this->original_title,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'age_rating' => $this->age_rating,
            'genres' => $this->genres ?? [],
            'premiere_date' => $this->premiere_date?->toDateString(),
            'poster_url' => $this->posterUrl(),
        ];
    }

    /**
     * Ścieżkę z bazy zamieniamy na pełny URL po stronie serwera.
     * Klient nie powinien wiedzieć, gdzie stoi storage ani sklejać
     * adresów — w Etapie 10 plakaty mogą pojechać na CDN i wtedy
     * zmieni się wyłącznie ta metoda.
     */
    private function posterUrl(): ?string
    {
        return $this->poster_path !== null
            ? asset('storage/'.ltrim($this->poster_path, '/'))
            : null;
    }
}
