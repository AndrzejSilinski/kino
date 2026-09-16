<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Services\Admin\HallLayoutGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Generator układu sali (Etap 7, blok E) — czysta funkcja, bez bazy i bez frameworka.
 */
final class HallLayoutGeneratorTest extends TestCase
{
    public function test_aisles_shift_seats_like_the_cinema_seeder(): void
    {
        // Szablon "small" z CinemaSeeder: 8 rzędów po 12 miejsc, przejście po 6.
        $seats = (new HallLayoutGenerator)->generate(8, 12, [6], 5, true, true);

        $firstRow = array_values(array_filter($seats, fn (array $s): bool => $s['y'] === 1));
        $this->assertCount(12, $firstRow);
        $this->assertSame(6, $firstRow[5]['x'], 'Miejsce 6 stoi przed przejściem.');
        $this->assertSame(8, $firstRow[6]['x'], 'Miejsce 7 jest za przejściem — kratka 7 zostaje pusta.');
        $this->assertSame(13, $firstRow[11]['x']);

        $this->assertSame('accessible', $firstRow[0]['type']);
        $this->assertSame('accessible', $firstRow[11]['type']);
        $this->assertSame('standard', $firstRow[1]['type']);

        $lastRow = array_values(array_filter($seats, fn (array $s): bool => $s['y'] === 8));
        $this->assertCount(6, $lastRow, 'Rząd kanap: połowa miejsc.');
        $this->assertSame([1, 3, 5, 7, 9, 11], array_column($lastRow, 'x'), 'Każde podwójne zajmuje dwie kratki.');
        $this->assertSame(['double'], array_values(array_unique(array_column($lastRow, 'type'))));

        $this->assertCount(7 * 12 + 6, $seats);
        $this->assertSame([5], array_values(array_unique(array_column($seats, 'category_id'))));
        $this->assertSame([null], array_values(array_unique(array_column($seats, 'id'))));
    }

    public function test_single_row_never_becomes_double_row(): void
    {
        $seats = (new HallLayoutGenerator)->generate(1, 4, [], 1, true, false);

        $this->assertSame(['standard'], array_values(array_unique(array_column($seats, 'type'))));
        $this->assertCount(4, $seats);
    }

    public function test_aisle_outside_row_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new HallLayoutGenerator)->generate(2, 10, [10], 1, false, false);
    }

    public function test_row_wider_than_grid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new HallLayoutGenerator)->generate(2, HallLayoutGenerator::MAX_COLUMNS, [5], 1, false, false);
    }
}
