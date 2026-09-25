<?php

use App\Models\Category;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function taskTemplateListUser(string $language): User
{
    return User::findOrFail(DB::table('users')->insertGetId([
        'workos_id' => 'user_'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => uniqid().'@example.com',
        'avatar' => '',
        'language' => $language,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

it('returns translatable fields as strings in the user language', function (string $language, string $name, string $category) {
    $kitchen = Category::create([
        'name' => ['en' => 'Kitchen', 'hu' => 'Konyha'],
        'icon' => 'utensils',
        'color' => '#F59E0B',
        'sort_order' => 1,
    ]);
    TaskTemplate::create([
        'name' => ['en' => 'Do the dishes', 'hu' => 'Mosogatás'],
        'description' => ['en' => 'Wash the dishes.', 'hu' => 'Edények elmosogatása.'],
        'category_id' => $kitchen->id,
        'icon' => 'utensils',
        'duration_minutes' => 20,
        'difficulty' => 'easy',
        'max_user' => 1,
    ]);

    Sanctum::actingAs(taskTemplateListUser($language));

    $template = $this->getJson('/api/task-templates')
        ->assertOk()
        ->json("task_templates.$category.0");

    expect($template['name'])->toBe($name)
        ->and($template['category']['name'])->toBe($category);
})->with([
    'hu' => ['hu', 'Mosogatás', 'Konyha'],
    'en' => ['en', 'Do the dishes', 'Kitchen'],
]);
