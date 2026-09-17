<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Exceptions\InvalidAvatarException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Zapis i usuwanie avatara (Etap 8, blok I). Kolejność jak przy plakacie (decyzje 150–151):
 *
 * 1. Plik PRZED transakcją: GD i zapis na dysk nie trzymają blokady wiersza users.
 * 2. Nowa nazwa przy każdej zmianie, nigdy nadpisanie: przeglądarka i CDN nie pokażą
 *    starego obrazu z cache, a nieudany zapis w bazie nie psuje obecnego avatara.
 * 3. Stary plik usuwany PO COMMIT — przy wycofaniu transakcji zostaje stary, poprawny stan.
 *    Nowy plik po nieudanej transakcji sprzątamy od razu.
 *
 * ADRES PUBLICZNY Z LOSOWĄ NAZWĄ (wariant przyjęty w planie Etapu 8): avatar to dane osobowe,
 * ale obrazek w <img> nie wyśle tokenu bearer, a endpoint z autoryzacją wymagałby fetch + blob
 * dla każdego wyświetlenia. 160 bitów losowości w nazwie (40 znaków hex) sprawia, że adresu nie da
 * się zgadnąć ani wyliczyć; zna go tylko ten, komu API go pokazało (dziś wyłącznie właściciel).
 * Nazwa NIE jest ULID-em: ten zdradza chwilę wgrania. Ograniczenie w README: kto dostanie adres,
 * zobaczy obraz, dopóki klient go nie zmieni.
 */
final class AvatarService
{
    public const DIRECTORY = 'avatars';

    public function __construct(private readonly AvatarImageProcessor $images) {}

    /** @throws InvalidAvatarException */
    public function store(User $user, string $sourcePath): User
    {
        $path = self::DIRECTORY.'/'.bin2hex(random_bytes(20)).'.jpg';

        if (! Storage::disk('public')->put($path, $this->images->toJpeg($sourcePath))) {
            throw InvalidAvatarException::storageFailed();
        }

        try {
            return $this->replacePath($user, $path);
        } catch (Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }
    }

    public function remove(User $user): User
    {
        return $this->replacePath($user, null);
    }

    private function replacePath(User $user, ?string $path): User
    {
        return DB::transaction(function () use ($user, $path): User {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $old = $fresh->avatar_path;

            $fresh->avatar_path = $path;
            $fresh->save();

            if ($old !== null && $old !== $path) {
                DB::afterCommit(fn () => $this->deleteFile($old));
            }

            return $fresh;
        });
    }

    /** Tylko nasze pliki: ścieżka z bazy nie może wskazać niczego poza avatars/. */
    private function deleteFile(string $path): void
    {
        if (preg_match('#\A'.self::DIRECTORY.'/[0-9a-f]{40}\.jpg\z#', $path) === 1) {
            Storage::disk('public')->delete($path);
        }
    }
}
