<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\ScreeningStatus;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Cinema;
use App\Models\Screening;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Zarządzanie kinami w panelu (Etap 7, blok D).
 *
 * Komponenty Livewire walidują formularz i wołają ten serwis; reguły, które
 * zależą od stanu bazy (nadchodzące seanse), pilnuje serwis pod blokadą wiersza.
 *
 * NIE MA USUWANIA. cinemas -> halls ma ON DELETE CASCADE, a sale mają seanse
 * i bilety z historią sprzedaży. Kino się wyłącza (is_active), a wyłączenie
 * jest zablokowane, dopóki kino ma nadchodzące seanse.
 *
 * SLUG nadawany raz, przy tworzeniu. Adres /cinemas/{slug} zapamiętują klienci
 * (wybór kina w 3.1 zadania) — zmiana nazwy nie może go zepsuć.
 *
 * INWALIDACJA CACHE po COMMIT: lista kin (CINEMAS) i generacja kina, bo nazwa
 * i strefa czasowa kina są też w wierszach repertuaru.
 */
final class CinemaAdminService
{
    public function __construct(
        private readonly CatalogCache $catalog,
    ) {}

    /**
     * @param  array{name: string, city: string, address: string, timezone: string}  $data
     */
    public function create(array $data): Cinema
    {
        return DB::transaction(function () use ($data): Cinema {
            // Szeregujemy tworzenie kin: dwa równoległe formularze z tą samą nazwą
            // dostałyby ten sam wolny slug i drugi INSERT rozbiłby się o UNIQUE.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['cinemas:create']);

            $cinema = new Cinema;
            $cinema->fill([
                'name' => $data['name'],
                'city' => $data['city'],
                'address' => $data['address'],
                'timezone' => $data['timezone'],
                'slug' => $this->uniqueSlug($data['city'].' '.$data['name']),
                'is_active' => true,
            ]);
            $cinema->save();

            $this->catalog->bump(CatalogCache::CINEMAS, CatalogCache::cinema($cinema->id));

            return $cinema;
        });
    }

    /**
     * @param  array{name: string, city: string, address: string, timezone: string}  $data
     *
     * @throws StructureChangeBlockedException gdy zmiana strefy dotknęłaby nadchodzących seansów
     */
    public function update(Cinema $cinema, array $data): Cinema
    {
        return DB::transaction(function () use ($cinema, $data): Cinema {
            $fresh = Cinema::query()->whereKey($cinema->id)->lockForUpdate()->firstOrFail();

            if ($data['timezone'] !== $fresh->timezone && ($upcoming = $this->upcomingScreenings($fresh)) > 0) {
                throw StructureChangeBlockedException::cinemaTimezoneChange($upcoming);
            }

            $fresh->fill([
                'name' => $data['name'],
                'city' => $data['city'],
                'address' => $data['address'],
                'timezone' => $data['timezone'],
            ]);
            $fresh->save();

            $this->catalog->bump(CatalogCache::CINEMAS, CatalogCache::cinema($fresh->id));

            return $fresh;
        });
    }

    /**
     * @throws StructureChangeBlockedException przy wyłączaniu kina z nadchodzącymi seansami
     */
    public function setActive(Cinema $cinema, bool $active): Cinema
    {
        return DB::transaction(function () use ($cinema, $active): Cinema {
            $fresh = Cinema::query()->whereKey($cinema->id)->lockForUpdate()->firstOrFail();

            if (! $active && ($upcoming = $this->upcomingScreenings($fresh)) > 0) {
                throw StructureChangeBlockedException::cinemaDeactivation($upcoming);
            }

            $fresh->is_active = $active;
            $fresh->save();

            $this->catalog->bump(CatalogCache::CINEMAS, CatalogCache::cinema($fresh->id));

            return $fresh;
        });
    }

    /**
     * Seanse zaplanowane, które się jeszcze nie skończyły — także trwające.
     * ends_at, a nie starts_at: na trwającym seansie są ludzie z biletami.
     */
    public function upcomingScreenings(Cinema $cinema): int
    {
        return Screening::query()
            ->whereRelation('hall', 'cinema_id', $cinema->id)
            ->where('status', ScreeningStatus::Scheduled)
            ->where('ends_at', '>', CarbonImmutable::now())
            ->count();
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source);
        $slug = $base;

        for ($suffix = 2; Cinema::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
