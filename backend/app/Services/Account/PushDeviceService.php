<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\PushDevice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rejestr urządzeń push (Etap 8, blok K).
 */
final class PushDeviceService
{
    /**
     * Rejestracja idempotentna po tokenie (INSERT … ON CONFLICT (token) DO UPDATE): ponowne wywołanie
     * przy każdym starcie aplikacji tylko odświeża last_seen_at. Token zarejestrowany wcześniej przez
     * inne konto przechodzi na bieżące — to ta sama instalacja, a poprzedni użytkownik się wylogował.
     * Upsert zamiast "SELECT, potem INSERT": dwie karty rejestrujące ten sam token naraz nie
     * wywrócą się na unikalnym indeksie.
     *
     * @return array{0: PushDevice, 1: bool} urządzenie i to, czy wiersz powstał teraz
     */
    public function register(User $user, string $token, string $platform, ?int $accessTokenId, ?string $replaces = null): array
    {
        return DB::transaction(function () use ($user, $token, $platform, $accessTokenId, $replaces): array {
            $now = CarbonImmutable::now();

            if ($replaces !== null && $replaces !== $token) {
                PushDevice::query()->where('user_id', $user->id)->where('token', $replaces)->delete();
            }

            $publicId = (string) Str::ulid();

            PushDevice::query()->upsert(
                [[
                    'public_id' => $publicId,
                    'user_id' => $user->id,
                    'personal_access_token_id' => $accessTokenId,
                    'token' => $token,
                    'platform' => $platform,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['token'],
                ['user_id', 'personal_access_token_id', 'platform', 'last_seen_at', 'updated_at'],
            );

            $device = PushDevice::query()->where('token', $token)->firstOrFail();

            $this->trim($user);

            return [$device, $device->public_id === $publicId];
        });
    }

    /** Wyrejestrowanie urządzenia właściciela; cudze albo nieistniejące -> false (kontroler: 404). */
    public function unregister(User $user, string $publicId): bool
    {
        return PushDevice::query()->where('user_id', $user->id)->where('public_id', $publicId)->delete() > 0;
    }

    /** Najstarsze urządzenia ponad limit: porzucone przeglądarki nie rosną bez końca. */
    private function trim(User $user): void
    {
        $keep = PushDevice::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit((int) config('push.max_devices_per_user'))
            ->pluck('id');

        PushDevice::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
    }
}
