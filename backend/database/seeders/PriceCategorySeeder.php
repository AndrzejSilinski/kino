<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PriceCategory;
use Illuminate\Database\Seeder;

class PriceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'standard',   'name' => 'Standardowe', 'color' => '#4B5563', 'sort_order' => 10],
            ['slug' => 'premium',    'name' => 'Premium',     'color' => '#2563EB', 'sort_order' => 20],
            ['slug' => 'vip',        'name' => 'VIP',         'color' => '#7C3AED', 'sort_order' => 30],
            ['slug' => 'love',       'name' => 'Miejsce podwójne', 'color' => '#DB2777', 'sort_order' => 40],
            ['slug' => 'accessible', 'name' => 'Miejsce dla osób z niepełnosprawnością', 'color' => '#059669', 'sort_order' => 50],
        ];

        foreach ($categories as $category) {
            // updateOrCreate zamiast create - seeder da sie uruchomic wielokrotnie
            // bez bledu duplikatu. To wymog przy `db:seed` na istniejacej bazie.
            PriceCategory::updateOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
