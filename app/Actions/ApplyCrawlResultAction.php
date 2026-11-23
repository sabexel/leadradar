<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Classification\DetectedSignal;
use App\DTOs\Crawl\CrawlResult;
use App\DTOs\Crawl\ExtractedEmail;
use App\Enums\CompanyType;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\LeadProduct;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Enums\SignalWeight;
use App\Models\Company;
use App\Models\CrawledPage;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Scoring\LeadScoringService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local, sync: store the facts from one crawled page, and finish the crawl (docs/05 `crawl` step).
 *
 * Stores extracted facts only (emails, signals, candidate links, agency terms) — never HTML or page text.
 */
final class ApplyCrawlResultAction
{
    public function __construct(
        private readonly ClassifyCompanyAction $classify,
        private readonly LeadScoringService $scoring,
        private readonly CompanyMatcher $matcher,
    ) {}

    public function apply(Company $company, CrawlResult $result): CrawledPage
    {
        return DB::transaction(function () use ($company, $result): CrawledPage {
            $page = CrawledPage::query()->updateOrCreate(
                ['company_id' => $company->id, 'url' => Str::limit($result->url, 700, '')],
                [
                    'http_status' => $result->status,
                    'fetched_at' => now(),
                    'content_hash' => $result->contentHash,
                    'page_type' => $result->pageType,
                    'extracted' => array_filter([
                        'skipped' => $result->skippedBecause,
                        'final_url' => $result->finalUrl !== $result->url ? $result->finalUrl : null,
                        'title' => $result->title,
                        'emails' => array_map(fn (ExtractedEmail $e): array => $e->toArray(), $result->emails),
                        'links' => $result->links,
                        'laravel' => array_map(fn (DetectedSignal $s): array => $s->toArray(), $result->laravelSignals),
                        'offshore' => array_map(fn (DetectedSignal $s): array => $s->toArray(), $result->offshoreSignals),
                        'agency_terms' => $result->agencyTerms,
                        'stack' => $result->stack,
                        'site_name' => $result->siteName,
                    ], fn (mixed $v): bool => $v !== null && $v !== []),
                ],
            );

            foreach ($result->emails as $email) {
                $this->storeEmail($company, $email);
            }

            if ($result->stack !== []) {
                $company->update(['tech_stack' => array_values(array_unique([...($company->tech_stack ?? []), ...$result->stack]))]);
            }

            if ($result->laravelSignals !== []) {
                $company->update(['laravel_evidence' => $this->merge($company->laravel_evidence ?? [], $result->laravelSignals)]);
            }

            return $page;
        });
    }

    /**
     * All pages done (or the site is off-limits): re-classify with what the site said, then move on.
     */
    public function finish(Company $company): Company
    {
        $pages = $company->crawledPages()->get();
        $fetched = $pages->filter(fn (CrawledPage $p): bool => ! isset($p->extracted['skipped']))->count();

        $terms = array_values(array_unique($pages->flatMap(fn (CrawledPage $p): array => $p->extracted['agency_terms'] ?? [])->all()));
        $offshore = array_values($pages->flatMap(fn (CrawledPage $p): array => $p->extracted['offshore'] ?? [])
            ->map(fn (array $s): DetectedSignal => new DetectedSignal($s['type'], SignalWeight::from($s['weight']), $s['detail'], $s['url'] ?? null))
            ->all());

        $robotsBlocked = ($pages->sortBy('id')->first()?->extracted['skipped'] ?? null) === 'robots';

        $company->recordEvent(
            PipelineStep::Crawl,
            $robotsBlocked ? PipelineOutcome::Skipped : PipelineOutcome::Ok,
            $robotsBlocked ? 'robots.txt disallows the home page — site not crawled' : "Crawled {$fetched} page(s)",
            [
                'pages' => $fetched,
                'emails' => $company->emailAddresses()->where('origin', EmailOrigin::Crawled)->count(),
                'laravel_signals' => count($company->laravel_evidence ?? []),
            ],
        );

        // Second classification pass with website evidence (docs/05: offshore → rejected).
        $this->classify->run($company, $terms === [] ? [] : [implode('; ', $terms)], $offshore);

        if ($company->isFinished()) {
            return $company;
        }

        // The Laravel gate (docs/06) can't change after the crawl — no later step adds evidence — so a company
        // without it stops here, before any MillionVerifier or Brave credit is spent on its people (D107).
        // Agency leads of any stack (D113) have no Laravel gate, so they always continue.
        $company->refresh();
        $this->applyBrandName($company, $pages);

        // The company's own site says it is a recruiter (D118) — whatever its name or job ad said.
        if (in_array('flag:recruiter', $company->tech_stack ?? [], true)) {
            $company->update(['company_type' => CompanyType::Recruiter, 'pipeline_step' => PipelineStep::Done]);
            $company->reject(RejectReason::Recruiter, 'Its own website describes it as a recruitment/staffing business');

            return $company;
        }

        if ($company->lead_product === LeadProduct::Laravel && ! $this->scoring->hasLaravelEvidence($company)) {
            $company->update(['pipeline_step' => PipelineStep::Done]);
            $company->reject(RejectReason::NoLaravelEvidence, 'No Laravel evidence on the site or in a job ad — stopped before email checks');

            return $company;
        }

        // An agency lead that can't reach the threshold even if every email check came back perfect stops here,
        // before any verification credit is spent (D119).
        if ($company->lead_product === LeadProduct::Agency) {
            $best = $this->scoring->bestPossibleAgencyScore($company);
            $threshold = (float) Setting::get('agency.qualify_threshold');

            if ($best < $threshold) {
                $company->update(['pipeline_step' => PipelineStep::Done, 'score' => $best]);
                $company->reject(RejectReason::BelowThreshold, "Best possible score {$best} is below {$threshold} (no served stack / not an agency) — stopped before email checks", [
                    'best_possible' => $best,
                    'threshold' => $threshold,
                ]);

                return $company;
            }
        }

        $company->update(['pipeline_step' => PipelineStep::BuildEmailCandidates]);

        return $company;
    }

    /**
     * Upsert the address with provenance; link it to a founder when the local part fits their name;
     * fill the company's contact/careers inbox if still empty.
     */
    private function storeEmail(Company $company, ExtractedEmail $found): void
    {
        $person = $found->kind === EmailKind::Other ? $this->personFor($company, $found->localPart()) : null;
        $kind = $person !== null ? EmailKind::Person : $found->kind;

        $email = EmailAddress::query()->firstOrCreate(
            ['company_id' => $company->id, 'email' => $found->email],
            [
                'person_id' => $person?->id,
                'kind' => $kind,
                'origin' => EmailOrigin::Crawled,
                'source_url' => $found->sourceUrl,
            ],
        );

        if ($person !== null && $person->email_id === null) {
            $person->update(['email_id' => $email->id]);
        }

        if ($kind === EmailKind::Contact && $company->contact_email_id === null) {
            $company->update(['contact_email_id' => $email->id]);
        }

        if ($kind === EmailKind::Careers && $company->careers_email_id === null) {
            $company->update(['careers_email_id' => $email->id]);
        }
    }

    /**
     * first@, first.last@, firstlast@, f.last@, flast@, first_last@, last@ (docs/04 patterns).
     */
    private function personFor(Company $company, string $localPart): ?Person
    {
        foreach ($company->people()->whereNull('resigned_on')->get() as $person) {
            $first = Str::lower(Str::ascii($person->first_name));
            $last = Str::lower(Str::ascii(str_replace(["'", ' '], '', $person->last_name)));

            if ($first === '' || $last === '') {
                continue;
            }

            $patterns = [$first, "{$first}.{$last}", "{$first}{$last}", "{$first[0]}.{$last}", "{$first[0]}{$last}", "{$first}_{$last}", "{$first}-{$last}", $last];

            if (in_array($localPart, $patterns, true)) {
                return $person;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  list<DetectedSignal>  $new
     * @return list<array<string, mixed>>
     */
    private function merge(array $existing, array $new): array
    {
        $merged = [];

        foreach ([...$existing, ...array_map(fn (DetectedSignal $s): array => $s->toArray(), $new)] as $signal) {
            $merged[($signal['type'] ?? '').'|'.($signal['detail'] ?? '')] ??= $signal;
        }

        return array_values($merged);
    }

    /**
     * Agency-search leads are named from the search result title, which is often SEO text ("Web Design Toronto").
     * The site's own name (og:site_name / application-name on the home page) replaces it when it looks like a
     * brand (D122).
     *
     * @param  Collection<int, CrawledPage>  $pages
     */
    public function applyBrandName(Company $company, Collection $pages): void
    {
        if (! $company->signals()->where('source', SignalSource::AgencySearch)->exists()) {
            return;
        }

        $brand = $pages->sortBy('id')
            ->map(fn (CrawledPage $p): ?string => is_string($p->extracted['site_name'] ?? null) ? $p->extracted['site_name'] : null)
            ->filter()
            ->first();

        if (! is_string($brand) || ! $this->looksLikeBrand($brand) || $brand === $company->name) {
            return;
        }

        $old = $company->name;
        $company->update(['name' => $brand, 'normalised_name' => $this->matcher->normalise($brand)]);
        $company->recordEvent(PipelineStep::Crawl, PipelineOutcome::Ok, "Renamed from \"{$old}\" to the site's own name \"{$brand}\"");
    }

    private function looksLikeBrand(string $name): bool
    {
        $length = mb_strlen($name);

        return $length >= 2 && $length <= 40
            && preg_match('/[|:]|\s[-–—]\s/u', $name) !== 1
            && preg_match('/\b(?:web ?design|website design|web development|agency in|company in|near me|best|top \d+)\b/i', $name) !== 1;
    }
}
