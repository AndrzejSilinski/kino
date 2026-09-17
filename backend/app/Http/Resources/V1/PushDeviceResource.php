<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\PushDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Urządzenie push (Etap 8, blok K). Bez tokenu FCM: klient go zna, a odpowiedź API mogłaby
 * trafić do logów pośrednika. id = public_id (ULID) — do wyrejestrowania.
 *
 * @mixin PushDevice
 */
final class PushDeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'platform' => $this->platform,
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
