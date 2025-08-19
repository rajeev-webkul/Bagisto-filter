<?php

use Illuminate\Support\Facades\Route;
use Webkul\AdvancedFilters\Http\Controllers\Admin\AdvancedFiltersController;

Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/advancedfilters'], function () {
    Route::controller(AdvancedFiltersController::class)->group(function () {
        Route::get('', 'index')->name('admin.advancedfilters.index');
    });
});