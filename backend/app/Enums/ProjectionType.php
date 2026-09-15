<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectionType: string
{
    case TwoD = '2d';
    case ThreeD = '3d';
    case Imax = 'imax';

    public function label(): string
    {
        return match ($this) {
            self::TwoD => '2D',
            self::ThreeD => '3D',
            self::Imax => 'IMAX',
        };
    }
}
