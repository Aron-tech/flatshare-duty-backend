<?php

namespace App\Observers;

use App\Models\Category;

class CategoryObserver
{
    public function created(Category $category): void
    {
        Category::invalidateCache();
    }

    public function updated(Category $category): void
    {
        Category::invalidateCache();
    }
}
