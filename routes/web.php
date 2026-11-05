<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PipelineSettingsController;
use App\Http\Controllers\RunController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies/bulk', [CompanyController::class, 'bulk'])->name('companies.bulk');
    Route::get('companies/export', [CompanyController::class, 'export'])->name('companies.export');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::patch('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::post('companies/{company}/retry', [CompanyController::class, 'retry'])->name('companies.retry');
    Route::post('companies/{company}/emails/{emailAddress}/reverify', [CompanyController::class, 'reverify'])
        ->scopeBindings()
        ->name('companies.emails.reverify');

    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::post('runs/discover', [RunController::class, 'discover'])->middleware('throttle:6,1')->name('runs.discover');
    Route::post('runs/failed/{uuid}/retry', [RunController::class, 'retryFailed'])->name('runs.failed.retry');
    Route::delete('runs/failed/{uuid}', [RunController::class, 'forgetFailed'])->name('runs.failed.forget');

    // LeadRadar settings — /settings/* belongs to the starter kit's account pages (D94).
    Route::get('configuration', [PipelineSettingsController::class, 'edit'])->name('configuration.edit');
    Route::put('configuration', [PipelineSettingsController::class, 'update'])->name('configuration.update');
    Route::post('configuration/test', [PipelineSettingsController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('configuration.test');
});

require __DIR__.'/settings.php';
