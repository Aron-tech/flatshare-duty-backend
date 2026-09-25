<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * @var array<int, array{en: string, hu: string, icon: string, color: string}>
     */
    public const array CATEGORIES = [
        ['en' => 'Kitchen', 'hu' => 'Konyha', 'icon' => 'utensils', 'color' => '#F59E0B'],
        ['en' => 'Bathroom', 'hu' => 'Fürdőszoba', 'icon' => 'bath', 'color' => '#3B82F6'],
        ['en' => 'Cleaning', 'hu' => 'Takarítás', 'icon' => 'spray-can', 'color' => '#10B981'],
        ['en' => 'Laundry', 'hu' => 'Mosás', 'icon' => 'shirt', 'color' => '#8B5CF6'],
        ['en' => 'Trash', 'hu' => 'Szemét', 'icon' => 'trash-2', 'color' => '#6B7280'],
        ['en' => 'Shopping', 'hu' => 'Bevásárlás', 'icon' => 'shopping-cart', 'color' => '#EF4444'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $index => $data) {
            Category::updateOrCreate(
                ['sort_order' => $index + 1],
                [
                    'name' => ['en' => $data['en'], 'hu' => $data['hu']],
                    'icon' => $data['icon'],
                    'color' => $data['color'],
                ],
            );
        }

        Category::invalidateCache();
    }
}
