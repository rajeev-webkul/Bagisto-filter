<?php

use Illuminate\Support\Facades\Route;
use Webkul\AdvancedFilters\Http\Controllers\Shop\AdvancedFiltersController;

Route::group(['middleware' => ['web', 'theme', 'locale', 'currency'], 'prefix' => 'advancedfilters'], function () {
    Route::get('', [AdvancedFiltersController::class, 'index'])->name('shop.advancedfilters.index');
});