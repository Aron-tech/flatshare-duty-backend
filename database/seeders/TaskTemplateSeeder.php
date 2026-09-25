<?php

namespace Database\Seeders;

use App\Enums\TaskDifficultyEnum;
use App\Models\Category;
use App\Models\TaskTemplate;
use Illuminate\Database\Seeder;

class TaskTemplateSeeder extends Seeder
{
    /**
     * Rows: [category (en name), name en, name hu, description en, description hu, icon, minutes, difficulty, max users]
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: TaskDifficultyEnum, 8: int}>
     */
    private const array TEMPLATES = [
        ['Kitchen', 'Do the dishes', 'Mosogatás', 'Wash, dry and put away the dishes.', 'Edények elmosogatása, elpakolása.', 'utensils', 20, TaskDifficultyEnum::EASY, 1],
        ['Kitchen', 'Empty the dishwasher', 'Mosogatógép kipakolása', 'Unload the dishwasher and put everything in its place.', 'A mosogatógép kipakolása és az edények elrendezése.', 'utensils', 10, TaskDifficultyEnum::EASY, 1],
        ['Kitchen', 'Wipe kitchen surfaces', 'Konyhapult letörlése', 'Clean the counters, stove and table.', 'A pult, a főzőlap és az asztal letörlése.', 'sparkles', 15, TaskDifficultyEnum::EASY, 1],
        ['Kitchen', 'Clean the fridge', 'Hűtő takarítása', 'Throw out expired food and wipe the shelves.', 'Lejárt ételek kidobása, polcok áttörlése.', 'refrigerator', 30, TaskDifficultyEnum::MEDIUM, 1],
        ['Kitchen', 'Clean the oven', 'Sütő tisztítása', 'Deep clean the oven and the baking trays.', 'A sütő és a tepsik alapos megtisztítása.', 'flame', 45, TaskDifficultyEnum::HARD, 1],
        ['Bathroom', 'Clean the toilet', 'WC takarítása', 'Scrub and disinfect the toilet.', 'A WC sikálása és fertőtlenítése.', 'toilet', 15, TaskDifficultyEnum::MEDIUM, 1],
        ['Bathroom', 'Clean the bathroom', 'Fürdőszoba takarítása', 'Clean the sink, shower, bathtub and mirror.', 'A mosdó, zuhany, kád és tükör tisztítása.', 'bath', 40, TaskDifficultyEnum::HARD, 1],
        ['Cleaning', 'Vacuum the floor', 'Porszívózás', 'Vacuum all rooms and common areas.', 'Minden szoba és közös tér kiporszívózása.', 'wind', 30, TaskDifficultyEnum::MEDIUM, 1],
        ['Cleaning', 'Mop the floor', 'Felmosás', 'Mop the floors of the flat.', 'A lakás padlójának felmosása.', 'brush-cleaning', 30, TaskDifficultyEnum::MEDIUM, 1],
        ['Cleaning', 'Dust the surfaces', 'Porolás', 'Dust shelves, furniture and windowsills.', 'Polcok, bútorok és ablakpárkányok leporolása.', 'sparkles', 20, TaskDifficultyEnum::EASY, 1],
        ['Cleaning', 'Clean the windows', 'Ablaktisztítás', 'Wash the windows inside and out.', 'Az ablakok tisztítása kívül-belül.', 'app-window', 45, TaskDifficultyEnum::HARD, 2],
        ['Laundry', 'Do the laundry', 'Mosás', 'Wash and hang up the shared laundry.', 'A közös ruhák kimosása és teregetése.', 'shirt', 20, TaskDifficultyEnum::EASY, 1],
        ['Laundry', 'Fold and put away laundry', 'Ruhák összehajtogatása', 'Fold the dry laundry and put it away.', 'A megszáradt ruhák összehajtogatása és elpakolása.', 'shirt', 20, TaskDifficultyEnum::EASY, 1],
        ['Trash', 'Take out the trash', 'Szemétkivitel', 'Take out the trash and replace the bag.', 'A szemét kivitele és új zsák behelyezése.', 'trash-2', 10, TaskDifficultyEnum::EASY, 1],
        ['Trash', 'Sort the recycling', 'Szelektív hulladék', 'Sort and take out the recycling.', 'A szelektív hulladék szétválogatása és kivitele.', 'recycle', 15, TaskDifficultyEnum::EASY, 1],
        ['Shopping', 'Grocery shopping', 'Bevásárlás', 'Buy the shared groceries and household items.', 'A közös élelmiszerek és háztartási cikkek megvásárlása.', 'shopping-cart', 60, TaskDifficultyEnum::MEDIUM, 2],
    ];

    public function run(): void
    {
        $categories = Category::all()->keyBy(fn (Category $category): string => $category->getTranslation('name', 'en'));

        foreach (self::TEMPLATES as [$category, $name_en, $name_hu, $description_en, $description_hu, $icon, $minutes, $difficulty, $max_user]) {
            $template = TaskTemplate::query()->where('name->en', $name_en)->firstOrNew();

            $template->fill([
                'name' => ['en' => $name_en, 'hu' => $name_hu],
                'description' => ['en' => $description_en, 'hu' => $description_hu],
                'category_id' => $categories[$category]->id,
                'icon' => $icon,
                'duration_minutes' => $minutes,
                'difficulty' => $difficulty,
                'max_user' => $max_user,
            ])->calculateBasePoints()->save();
        }

        TaskTemplate::invalidateCache();
    }
}
