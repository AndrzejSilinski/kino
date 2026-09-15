<?php

namespace App\Http\Resources\V1;

use App\Models\Cinema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kino w widoku publicznym.
 *
 * Nie ma tu pola is_active — endpoint zwraca wyłącznie kina czynne,
 * więc flaga niosłaby zawsze true i tylko zaśmiecała kontrakt.
 * Gdyby kiedyś trzeba było pokazać nieczynne (panel admina), zrobi to
 * osobny zasób, a ten pozostanie kontraktem dla klienta.
 *
 * timezone wychodzi na zewnątrz celowo: klient musi wiedzieć, w jakiej
 * strefie interpretować daty repertuaru, jeśli sam buduje kalendarz.
 *
 * @mixin Cinema
 */
class CinemaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'city' => $this->city,
            'address' => $this->address,
            'timezone' => $this->timezone,
        ];
    }
}
