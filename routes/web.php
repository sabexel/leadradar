<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies/bulk', [CompanyController::class, 'bulk'])->name('companies.bulk');
});

require __DIR__.'/settings.php';
