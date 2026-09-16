<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\ScreeningStatus;
use App\Exceptions\StructureChangeBlockedException;
use App\Models\Cinema;
use App\Models\Hall;
use App\Models\Screening;
use App\Support\CatalogCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Zarządzanie salami w panelu (Etap 7, blok D). Układ miejsc: blok E.
 *
 * Sala należy do jednego kina na zawsze — przeniesienie sali z seansami
 * i biletami do innego miasta nie ma sensu biznesowego. Bez usuwania:
 * seanse wskazują salę kluczem RESTRICT, a bilety wskazują jej miejsca.
 *
 * Nowa sala ma siatkę 0 x 0: miejsca i wymiary nada edytor układu (blok E).
 *
 * INWALIDACJA: generacja kina (sale są w wierszach repertuaru).
 */
final class HallAdminService
{
    public function __construct(
        private readonly CatalogCache $catalog,
    ) {}

    /**
     * @param  array{name: string, projection_types: list<string>}  $data
     *
     * @throws StructureChangeBlockedException gdy nazwę zajęto równolegle
     */
    public function create(Cinema $cinema, array $data): Hall
    {
        try {
            return DB::transaction(function () use ($cinema, $data): Hall {
                $hall = new Hall;
                $hall->fill([
                    'cinema_id' => $cinema->id,
                    'name' => $data['name'],
                    'projection_types' => array_values($data['projection_types']),
                    'grid_rows' => 0,
                    'grid_cols' => 0,
                    'is_active' => true,
                ]);
                $hall->save();

                $this->catalog->bump(CatalogCache::cinema($cinema->id));

                return $hall;
            });
        } catch (UniqueConstraintViolationException) {
            // Walidacja formularza sprawdziła nazwę, ale drugi admin mógł ją zająć
            // w międzyczasie. UNIQUE (cinema_id, name) w bazie to ostatnia linia.
            throw StructureChangeBlockedException::hallNameTaken($data['name']);
        }
    }

    /**
     * @param  array{name: string, projection_types: list<string>}  $data
     *
     * @throws StructureChangeBlockedException gdy usuwany typ projekcji mają nadchodzące seanse
     */
    public function update(Hall $hall, array $data): Hall
    {
        try {
            return DB::transaction(function () use ($hall, $data): Hall {
                $fresh = Hall::query()->whereKey($hall->id)->lockForUpdate()->firstOrFail();

                $removed = array_values(array_diff($fresh->projection_types ?? [], $data['projection_types']));

                if ($removed !== []) {
                    $upcoming = $this->upcomingScreenings($fresh)->whereIn('projection_type', $removed)->count();

                    if ($upcoming > 0) {
                        throw StructureChangeBlockedException::hallProjectionTypesInUse($removed, $upcoming);
                    }
                }

                $fresh->name = $data['name'];
                $fresh->projection_types = array_values($data['projection_types']);
                $fresh->save();

                $this->catalog->bump(CatalogCache::cinema($fresh->cinema_id));

                return $fresh;
            });
        } catch (UniqueConstraintViolationException) {
            throw StructureChangeBlockedException::hallNameTaken($data['name']);
        }
    }

    /**
     * @throws StructureChangeBlockedException przy wyłączaniu sali z nadchodzącymi seansami
     */
    public function setActive(Hall $hall, bool $active): Hall
    {
        return DB::transaction(function () use ($hall, $active): Hall {
            $fresh = Hall::query()->whereKey($hall->id)->lockForUpdate()->firstOrFail();

            if (! $active && ($upcoming = $this->upcomingScreenings($fresh)->count()) > 0) {
                throw StructureChangeBlockedException::hallDeactivation($upcoming);
            }

            $fresh->is_active = $active;
            $fresh->save();

            $this->catalog->bump(CatalogCache::cinema($fresh->cinema_id));

            return $fresh;
        });
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Screening> */
    private function upcomingScreenings(Hall $hall)
    {
        return Screening::query()
            ->where('hall_id', $hall->id)
            ->where('status', ScreeningStatus::Scheduled)
            ->where('ends_at', '>', CarbonImmutable::now());
    }
}
