<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A relation accessed on one model of a collection is loaded for the whole collection, so a missed eager load does not cause N+1 queries.
        Model::automaticallyEagerLoadRelationships();

        // Outside production, filling an attribute that is not fillable fails instead of being dropped silently.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
