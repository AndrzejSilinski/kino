<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\ScreeningStatus;
use App\Exceptions\InvalidScreeningException;
use App\Exceptions\RepertoireCopyBlockedException;
use App\Exceptions\ScreeningConflictException;
use App\Models\Cinema;
use App\Models\Screening;
use App\Support\ScreeningTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kopiowanie repertuaru kina z dnia na dzień (Etap 7, blok H; wymóg 2.2).
 *
 * ZASADY:
 * - kopiujemy seanse zaplanowane i zakończone z dnia źródłowego (odwołane — nie),
 *   z cennikami; godzina LOKALNA zostaje ta sama, więc po zmianie czasu 18:00
 *   to nadal 18:00 w kinie (inny moment w UTC), a slot liczymy od nowa z bieżącą
 *   długością filmu i buforami;
 * - WSZYSTKO ALBO NIC: jeden seans z kolizją albo błędem = żadnych zmian i raport;
 * - IDEMPOTENCJA: seans identyczny z już istniejącym (sala, film, moment, projekcja,
 *   wersja) oznaczamy "już jest" i pomijamy — podwójne kliknięcie niczego nie dubluje.
 *
 * JEDNE REGUŁY: każdy seans przechodzi przez ScreeningAdminService::create(), czyli
 * te same blokady sal, walidację, kolizje i 23P01 co ręczne dodawanie. Każde create()
 * to zagnieżdżona transakcja (SAVEPOINT): błąd jednego seansu cofa tylko jego
 * savepoint, więc zbieramy wszystkie problemy, a na końcu decydujemy o całości.
 *
 * PODGLĄD to ten sam przebieg zakończony ROLLBACK — pokazuje dokładnie to, co zrobi
 * kopiowanie w tej chwili (kosztem kilku numerów z sekwencji id).
 */
final class RepertoireCopyService
{
    public function __construct(
        private readonly ScreeningAdminService $screenings,
    ) {}

    /**
     * @return array{created: int, existing: int, items: list<array{hall: string, time: string, movie: string, status: string, message: ?string}>}
     */
    public function preview(Cinema $cinema, string $sourceDate, string $targetDate): array
    {
        // Podgląd niczego nie zapisuje: ROLLBACK zawsze, także po sukcesie. Razem z nim
        // Laravel porzuca zarejestrowane "po COMMIT" podbicia cache.
        DB::beginTransaction();

        try {
            return $this->run($cinema, $sourceDate, $targetDate);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return array{created: int, existing: int, items: list<array{hall: string, time: string, movie: string, status: string, message: ?string}>}
     *
     * @throws RepertoireCopyBlockedException
     */
    public function copy(Cinema $cinema, string $sourceDate, string $targetDate): array
    {
        return DB::transaction(function () use ($cinema, $sourceDate, $targetDate): array {
            $report = $this->run($cinema, $sourceDate, $targetDate);

            if (in_array('problem', array_column($report['items'], 'status'), true)) {
                throw RepertoireCopyBlockedException::problems($report['items']);
            }

            return $report;
        });
    }

    /**
     * @return array{created: int, existing: int, items: list<array{hall: string, time: string, movie: string, status: string, message: ?string}>}
     */
    private function run(Cinema $cinema, string $sourceDate, string $targetDate): array
    {
        $timeline = ScreeningTimeline::fromConfig();
        // localStart() sprawdza też zapis obu dat (zły format -> InvalidScreeningException).
        $sourceStart = $timeline->localStart($sourceDate, '00:00', $cinema->timezone);
        $sourceEnd = $timeline->localStart(CarbonImmutable::parse($sourceDate)->addDay()->toDateString(), '00:00', $cinema->timezone);
        $timeline->localStart($targetDate, '00:00', $cinema->timezone);

        if ($sourceDate === $targetDate) {
            throw RepertoireCopyBlockedException::sameDay();
        }

        if ($targetDate <= CarbonImmutable::now($cinema->timezone)->toDateString()) {
            throw RepertoireCopyBlockedException::targetTooEarly();
        }

        // Szereguje kopiowania na ten sam dzień tego kina (podwójne kliknięcie,
        // dwie karty). Blokada doradcza znika sama z końcem transakcji.
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["repertoire-copy:{$cinema->id}:{$targetDate}"]);

        // Kolejność sal rosnąco po id: create() blokuje salę — ta sama kolejność
        // blokad co w ScreeningAdminService, bez zakleszczeń.
        $sources = Screening::query()
            ->with(['movie:id,title', 'hall:id,name', 'prices'])
            ->whereRelation('hall', 'cinema_id', $cinema->id)
            ->where('status', '!=', ScreeningStatus::Cancelled)
            ->where('starts_at', '>=', $sourceStart)
            ->where('starts_at', '<', $sourceEnd)
            ->orderBy('hall_id')
            ->orderBy('starts_at')
            ->get();

        if ($sources->isEmpty()) {
            throw RepertoireCopyBlockedException::emptySource();
        }

        $report = ['created' => 0, 'existing' => 0, 'items' => []];

        foreach ($sources as $source) {
            $time = $source->starts_at->setTimezone($cinema->timezone)->format('H:i');
            $item = ['hall' => $source->hall->name, 'time' => $time, 'movie' => $source->movie->title, 'status' => 'create', 'message' => null];

            try {
                $startsAt = $timeline->localStart($targetDate, $time, $cinema->timezone);

                if ($this->alreadyThere($source, $startsAt)) {
                    $item['status'] = 'exists';
                    $report['existing']++;
                } else {
                    $this->screenings->create([
                        'hall_id' => $source->hall_id,
                        'movie_id' => $source->movie_id,
                        'date' => $targetDate,
                        'time' => $time,
                        'projection_type' => $source->projection_type,
                        'language_version' => $source->language_version,
                        'prices' => $source->prices->pluck('price', 'price_category_id')->map(fn ($price): int => (int) $price)->all(),
                    ]);
                    $report['created']++;
                }
            } catch (InvalidScreeningException|ScreeningConflictException $e) {
                $item['status'] = 'problem';
                $item['message'] = $e->getMessage();
            }

            $report['items'][] = $item;
        }

        return $report;
    }

    private function alreadyThere(Screening $source, CarbonImmutable $startsAt): bool
    {
        return Screening::query()
            ->where('hall_id', $source->hall_id)
            ->where('movie_id', $source->movie_id)
            ->where('starts_at', $startsAt)
            ->where('projection_type', $source->projection_type)
            ->where('language_version', $source->language_version)
            ->where('status', '!=', ScreeningStatus::Cancelled)
            ->exists();
    }
}
