<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Places\PlaceLead;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Support\SuppressionList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Local, sync (D111): one Google Place without a website → a "needs a website" lead.
 * Skips the Laravel pipeline. Sole traders / unregistered places are kept; email outreach needs consent.
 */
final class IngestWebsitePlaceAction
{
    public function __construct(
        private readonly CompanyMatcher $matcher,
        private readonly SuppressionList $suppressions,
    ) {}

    /**
     * @phpstan-impure
     */
    public function createdToday(): int
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');

        return Signal::query()
            ->where('source', SignalSource::GooglePlaces)
            ->where('created_at', '>=', CarbonImmutable::now($timezone)->startOfDay()->utc())
            ->count();
    }

    /**
     * @phpstan-impure
     */
    public function remainingToday(): int
    {
        return max(0, (int) Setting::get('source.places.per_day') - $this->createdToday());
    }

    public function run(PlaceLead $place, string $city, string $placeType, ?ScrapeRun $run = null): bool
    {
        $minReviews = max(0, (int) Setting::get('source.places.min_reviews'));

        if ($place->reviewCount < $minReviews) {
            return false;
        }

        $normalised = $this->matcher->normalise($place->name);

        if ($normalised === '') {
            return false;
        }

        $probe = new Company([
            'name' => $place->name,
            'normalised_name' => $normalised,
            'domain' => null,
            'registration_number' => null,
        ]);

        if ($this->suppressions->isSuppressed($probe)) {
            return false;
        }

        $company = Cache::lock("ingest-place:{$place->placeId}", 10)->block(5, fn () => DB::transaction(function () use ($place, $normalised, $city, $placeType): ?Company {
            if (Company::query()->where('google_place_id', $place->placeId)->exists()) {
                return null;
            }

            if (Company::query()->where('normalised_name', $normalised)->where('lead_product', LeadProduct::Website)->exists()) {
                return null;
            }

            $score = $this->score($place);

            $company = Company::create([
                'name' => $place->name,
                'normalised_name' => $normalised,
                'google_place_id' => $place->placeId,
                'country' => 'GB',
                'location' => $place->locality ?? $city,
                'registered_address' => $place->address === null ? null : ['formatted' => $place->address],
                'phone' => $place->phone,
                'review_count' => $place->reviewCount,
                'lead_product' => LeadProduct::Website,
                'email_consent_required' => true,
                'status' => LeadStatus::Qualified,
                'pipeline_step' => PipelineStep::Done,
                'score' => $score,
                'score_breakdown' => [
                    'product' => 'website',
                    'reviews' => $place->reviewCount,
                    'rating' => $place->rating,
                    'has_phone' => $place->phone !== null,
                ],
                'qualified_at' => now(),
                'notes' => 'Needs a website (Google Places). No site listed. Cold email needs prior consent for sole traders / unregistered businesses (PECR) — prefer phone.',
            ]);

            Signal::create([
                'company_id' => $company->id,
                'source' => SignalSource::GooglePlaces,
                'external_id' => $place->placeId,
                'title' => "{$placeType} in {$city} — {$place->reviewCount} reviews, no website",
                'employer_name' => $place->name,
                'location' => $company->location,
                'url' => $place->mapsUrl,
                'posted_at' => now(),
                'raw' => [
                    'place_id' => $place->placeId,
                    'types' => $place->types,
                    'rating' => $place->rating,
                    'review_count' => $place->reviewCount,
                    'search' => ['city' => $city, 'type' => $placeType],
                ],
            ]);

            return $company;
        }));

        if ($company === null) {
            return false;
        }

        $run?->increment('signals_found');
        $run?->increment('companies_created');
        $run?->increment('qualified_count');

        $company->recordEvent(PipelineStep::Done, PipelineOutcome::Ok, "Website lead from Google Places ({$place->reviewCount} reviews, no website)", [
            'place_id' => $place->placeId,
        ]);

        return true;
    }

    private function score(PlaceLead $place): float
    {
        $fromReviews = min(4.0, $place->reviewCount / 25);
        $fromRating = $place->rating === null ? 0.0 : min(3.0, $place->rating * 0.6);
        $phone = $place->phone !== null ? 2.0 : 0.0;

        return min(10.0, round(1.0 + $fromReviews + $fromRating + $phone, 1));
    }
}
