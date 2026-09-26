<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\TaskTemplate;

class CategoryObserver
{
    /**
     * The cached task templates contain their category.
     */
    public function saved(Category $category): void
    {
        Category::invalidateCache();
        TaskTemplate::invalidateCache();
    }

    public function deleted(Category $category): void
    {
        $this->saved($category);
    }
}
