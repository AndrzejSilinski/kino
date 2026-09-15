<?php

namespace App\Http\Resources\V1;

use App\Models\Hall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sala kinowa.
 *
 * grid_rows i grid_cols to wymiary siatki, na której frontend rysuje
 * plan. Wysyłamy je razem z salą, a nie wyliczamy na kliencie z maksimum
 * position_x/position_y, bo sala może mieć puste kolumny (przejście
 * między sekcjami) i wyliczone wymiary byłyby za małe.
 *
 * @mixin Hall
 */
class HallResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'grid' => [
                'rows' => $this->grid_rows,
                'columns' => $this->grid_cols,
            ],
            'projection_types' => $this->projection_types ?? [],
            // Kino dołączamy tylko wtedy, gdy zostało wcześniej
            // załadowane przez with() — whenLoaded chroni przed N+1
            // i przed niepotrzebnym zapytaniem na liście repertuaru.
            'cinema' => new CinemaResource($this->whenLoaded('cinema')),
        ];
    }
}
