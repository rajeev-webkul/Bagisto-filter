<?php

namespace Webkul\AdvancedFilters\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use  Webkul\Product\Repositories\ProductRepository as BaseProductRepository;
use  Webkul\AdvancedFilters\Repositories\ProductRepository as CustomProductRepository;

class AdvancedFiltersServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/admin-routes.php');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/shop-routes.php');

        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'advancedfilters');

        $this->app->bind(BaseProductRepository::class, CustomProductRepository::class);

        $this->publishes([
            __DIR__ . '/../Resources/views/components/products/card.blade.php' 
                => resource_path('themes/default/views/components/products/card.blade.php'),

            __DIR__ . '/../Resources/views/products/view.blade.php' 
                => resource_path('themes/default/views/products/view.blade.php'),

            __DIR__ . '/../Resources/views/categories/filters.blade.php' 
                => resource_path('themes/default/views/categories/filters.blade.php'),
        ]);

        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'advancedfilters');

        Event::listen('bagisto.admin.layout.head', function($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('advancedfilters::admin.layouts.style');
        });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/admin-menu.php', 'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/acl.php', 'acl'
        );
    }
}