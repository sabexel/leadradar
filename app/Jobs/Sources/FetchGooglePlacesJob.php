<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestWebsitePlaceAction;
use App\Enums\ExternalService;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Services\Places\GooglePlacesService;
use App\Services\Support\DailyTarget;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Discovery source (D111): ONE Places Text Search call for one business type in one city.
 * Keeps only places with no website and enough reviews; position continues via nextPageToken.
 */
final class FetchGooglePlacesJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public const MAX_PAGES_PER_RUN = 3;

    public function __construct(
        public string $city,
        public string $placeType,
        public int $take,
        public int $page = 1,
        public ?string $pageToken = null,
        public ?int $scrapeRunId = null,
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "places:{$this->placeType}:{$this->city}:{$this->page}:{$this->scrapeRunId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::GooglePlaces->value)];
    }

    public static function cursorKey(string $city, string $placeType): string
    {
        return 'source.places.cursor.'.mb_strtolower(trim($placeType)).'|'.mb_strtolower(trim($city));
    }

    public function handle(GooglePlacesService $places, DailyTarget $target, IngestWebsitePlaceAction $ingest): void
    {
        if ($this->take < 1 || $target->reached() || $ingest->remainingToday() === 0) {
            return;
        }

        $key = self::cursorKey($this->city, $this->placeType);
        $token = $this->pageToken ?? (is_string($stored = Setting::get($key)) && $stored !== '' ? $stored : null);

        [$called, $page] = $this->withinServiceLimits(
            fn () => $places->searchText($this->city, $this->placeType, $token),
        );

        if (! $called || $page === null) {
            return;
        }

        $run = $this->scrapeRunId === null ? null : ScrapeRun::find($this->scrapeRunId);
        $created = 0;

        foreach ($page->places as $place) {
            if ($created >= $this->take || $ingest->remainingToday() === 0) {
                break;
            }

            $created += $ingest->run($place, $this->city, $this->placeType, $run) ? 1 : 0;
        }

        $left = $this->take - $created;
        $exhausted = $page->nextPageToken === null;

        Setting::put($key, $exhausted ? null : $page->nextPageToken);

        if (! $exhausted && $left > 0 && $this->page < self::MAX_PAGES_PER_RUN && $ingest->remainingToday() > 0) {
            self::dispatch($this->city, $this->placeType, $left, $this->page + 1, $page->nextPageToken, $this->scrapeRunId);
        }
    }
}
