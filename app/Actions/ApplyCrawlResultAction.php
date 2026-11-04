<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Classification\DetectedSignal;
use App\DTOs\Crawl\CrawlResult;
use App\DTOs\Crawl\ExtractedEmail;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\SignalWeight;
use App\Models\Company;
use App\Models\CrawledPage;
use App\Models\EmailAddress;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local, sync: store the facts from one crawled page, and finish the crawl (docs/05 `crawl` step).
 *
 * Stores extracted facts only (emails, signals, candidate links, agency terms) — never HTML or page text.
 */
final class ApplyCrawlResultAction
{
    public function __construct(private readonly ClassifyCompanyAction $classify) {}

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
                    ], fn (mixed $v): bool => $v !== null && $v !== []),
                ],
            );

            foreach ($result->emails as $email) {
                $this->storeEmail($company, $email);
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

        if (! $company->isFinished()) {
            $company->update(['pipeline_step' => PipelineStep::BuildEmailCandidates]);
        }

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
}
