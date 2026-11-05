<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Dashboard\DateRange;
use App\Queries\DashboardQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardQuery $query): Response
    {
        $range = DateRange::fromRequest(
            $request->string('range')->toString() ?: null,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
            (string) config('leadradar.timezone', 'Europe/London'),
        );

        return Inertia::render('dashboard', $query->get($range));
    }
}
