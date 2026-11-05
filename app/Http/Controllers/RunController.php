<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartDiscoveryRunAction;
use App\Enums\RunTrigger;
use App\Models\ScrapeRun;
use App\Queries\RunsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class RunController extends Controller
{
    public function index(RunsQuery $query): Response
    {
        $runs = $query->runs();

        return Inertia::render('runs/index', [
            'runs' => [
                'data' => $runs->getCollection()->map(fn (ScrapeRun $run): array => RunsQuery::run($run))->values()->all(),
                'total' => $runs->total(),
                'currentPage' => $runs->currentPage(),
                'lastPage' => $runs->lastPage(),
                'prevPageUrl' => $runs->previousPageUrl(),
                'nextPageUrl' => $runs->nextPageUrl(),
            ],
            'apiUsage' => $query->apiUsage(),
            'failedJobs' => $query->failedJobs(),
            'failedJobCount' => $query->failedJobCount(),
        ]);
    }

    /**
     * "Run discovery now": the same as `leadradar:discover --manual` (it only queues the job-board searches).
     */
    public function discover(StartDiscoveryRunAction $discovery): RedirectResponse
    {
        $result = $discovery->run(RunTrigger::Manual);
        $started = $result['dispatched'] !== [];

        $message = $result['message'];
        if ($result['skipped'] !== []) {
            $message .= ' Skipped: '.implode('; ', $result['skipped']).'.';
        }

        Inertia::flash('toast', ['type' => $started ? 'success' : 'warning', 'message' => $message]);

        return back();
    }

    public function retryFailed(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Job queued again']);

        return back();
    }

    public function forgetFailed(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:forget', ['id' => $uuid]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Failed job removed']);

        return back();
    }
}
