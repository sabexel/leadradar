<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies/bulk', [CompanyController::class, 'bulk'])->name('companies.bulk');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::patch('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::post('companies/{company}/retry', [CompanyController::class, 'retry'])->name('companies.retry');
    Route::post('companies/{company}/emails/{emailAddress}/reverify', [CompanyController::class, 'reverify'])
        ->scopeBindings()
        ->name('companies.emails.reverify');
});

require __DIR__.'/settings.php';
