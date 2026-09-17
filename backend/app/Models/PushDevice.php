<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Urządzenie z tokenem FCM (Etap 8, blok K). Na zewnątrz identyfikowane przez public_id (ULID):
 * sekwencyjne id w adresie zdradzałoby liczbę urządzeń, a token FCM w adresie trafiałby do logów.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int|null $personal_access_token_id
 * @property string $token
 * @property string $platform
 * @property CarbonImmutable $last_seen_at
 */
final class PushDevice extends Model
{
    public const PLATFORMS = ['web', 'android', 'ios'];

    /** Token nie wychodzi w żadnej serializacji modelu (logi, dumpy, odpowiedzi). */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (PushDevice $device): void {
            $device->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ostatnie znaki tokenu do logów i raportów — nigdy cały token (zasady bezpieczeństwa Etapu 8). */
    public function tokenTail(): string
    {
        return '…'.substr($this->token, -6);
    }
}
