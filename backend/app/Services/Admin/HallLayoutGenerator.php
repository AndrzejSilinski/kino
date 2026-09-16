<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\SeatType;
use InvalidArgumentException;

/**
 * Generator układu sali: prostokąt rzędów z przejściami (Etap 7, blok E).
 *
 * Czysta funkcja bez bazy — wynik to szkic dla edytora, który admin poprawia
 * klikaniem, a zapisuje HallLayoutService (i dopiero tam jest pełna walidacja).
 * Ta sama geometria co w CinemaSeeder: przejście po miejscu N przesuwa kolejne
 * miejsca o jedną kratkę w prawo, a miejsce podwójne zajmuje kratki x i x+1.
 */
final class HallLayoutGenerator
{
    /** Rzędy oznaczamy literami A–Z. */
    public const MAX_ROWS = 26;

    public const MAX_COLUMNS = 40;

    /**
     * @param  list<int>  $aislesAfter  numery miejsc, po których jest przejście
     * @return list<array{id: null, x: int, y: int, type: string, category_id: int, active: bool, label: null}>
     */
    public function generate(
        int $rows,
        int $seatsPerRow,
        array $aislesAfter,
        int $categoryId,
        bool $doubleLastRow,
        bool $accessibleFirstRowEdges,
    ): array {
        $aislesAfter = array_values(array_unique($aislesAfter));
        sort($aislesAfter);

        if ($rows < 1 || $rows > self::MAX_ROWS || $seatsPerRow < 1) {
            throw new InvalidArgumentException('Nieprawidłowe wymiary sali.');
        }

        foreach ($aislesAfter as $aisle) {
            if ($aisle < 1 || $aisle >= $seatsPerRow) {
                throw new InvalidArgumentException("Przejście po miejscu {$aisle} jest poza rzędem.");
            }
        }

        if ($seatsPerRow + count($aislesAfter) > self::MAX_COLUMNS) {
            throw new InvalidArgumentException('Rząd z przejściami przekracza '.self::MAX_COLUMNS.' kratek.');
        }

        $seats = [];

        for ($row = 1; $row <= $rows; $row++) {
            $isDoubleRow = $doubleLastRow && $row === $rows && $rows > 1;

            if ($isDoubleRow) {
                // Kanapy: połowa miejsc, każda na dwóch kratkach, bez przejść.
                for ($i = 1; $i <= intdiv($seatsPerRow, 2); $i++) {
                    $seats[] = $this->seat($i * 2 - 1, $row, SeatType::Double, $categoryId);
                }

                continue;
            }

            for ($number = 1; $number <= $seatsPerRow; $number++) {
                $offset = count(array_filter($aislesAfter, static fn (int $aisle): bool => $number > $aisle));
                $accessible = $accessibleFirstRowEdges && $row === 1 && ($number === 1 || $number === $seatsPerRow);

                $seats[] = $this->seat(
                    $number + $offset,
                    $row,
                    $accessible ? SeatType::Accessible : SeatType::Standard,
                    $categoryId,
                );
            }
        }

        return $seats;
    }

    /** @return array{id: null, x: int, y: int, type: string, category_id: int, active: bool, label: null} */
    private function seat(int $x, int $y, SeatType $type, int $categoryId): array
    {
        return [
            'id' => null,
            'x' => $x,
            'y' => $y,
            'type' => $type->value,
            'category_id' => $categoryId,
            'active' => true,
            'label' => null,
        ];
    }
}
