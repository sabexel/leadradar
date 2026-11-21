<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Directories\LaravelPartner;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Signal;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Support\SuppressionList;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Local, sync (P4.1, D108): one Laravel Partner → a UK company with its website already known, sent into the
 * pipeline at `registry_search`. The partner listing itself is the Laravel evidence (a `laravel_partners`
 * signal, scored like a job ad — D108). A partner already known gets the signal attached and is rescored.
 */
final class IngestLaravelPartnerAction
{
    /**
     * Words in a partner's own description that place it in the UK.
     */
    private const UK_WORDS = '/\b(?:UK|U\.K\.|United Kingdom|Great Britain|Britain|British|England|English|Scotland|Scottish|Wales|Welsh|Northern Ireland|London|Manchester|Birmingham|Leeds|Bristol|Liverpool|Sheffield|Nottingham|Leicester|Newcastle|Glasgow|Edinburgh|Cardiff|Belfast|Brighton|Cambridge|Oxford)\b/';

    public function __construct(
        private readonly CompanyMatcher $matcher,
        private readonly SuppressionList $suppressions,
        private readonly AdvanceLeadPipelineAction $advance,
        private readonly ApplyReverificationAction $rescore,
    ) {}

    /**
     * The directory gives regions, not countries: a UK partner lists Europe and has a .uk website or says
     * where it is in its description. Everyone else can't pass the Companies House gates (D7).
     */
    public function isUk(LaravelPartner $partner): bool
    {
        if (! in_array('europe', $partner->regions, true)) {
            return false;
        }

        $domain = $this->matcher->domainOf($partner->website);

        return ($domain !== null && str_ends_with($domain, '.uk')) || preg_match(self::UK_WORDS, $partner->description) === 1;
    }

    /**
     * @return bool true when a new company was created
     */
    public function run(LaravelPartner $partner, ?ScrapeRun $run = null): bool
    {
        $domain = $this->matcher->domainOf($partner->website);
        $normalised = $this->matcher->normalise($partner->name);

        if (! $this->isUk($partner) || $domain === null || $normalised === '') {
            return false;
        }

        if ($this->suppressions->isSuppressed(new Company(['domain' => $domain]))) {
            return false;
        }

        [$company, $created, $signal] = Cache::lock("ingest-company:{$normalised}", 10)->block(5, fn () => DB::transaction(function () use ($partner, $domain, $normalised): array {
            $company = Company::query()
                ->where('domain', $domain)
                ->orWhere('normalised_name', $normalised)
                ->oldest('id')
                ->first();
            $created = false;

            if ($company === null) {
                $company = Company::create([
                    'name' => $partner->name,
                    'normalised_name' => $normalised,
                    'website' => 'https://'.$domain,
                    'domain' => $domain,
                    'country' => 'GB',
                    'status' => LeadStatus::Discovered,
                    'pipeline_step' => PipelineStep::RegistrySearch,
                ]);
                $created = true;
            }

            $signal = Signal::query()->updateOrCreate(
                ['source' => SignalSource::LaravelPartners, 'external_id' => $partner->slug],
                [
                    'company_id' => $company->id,
                    'title' => 'Laravel Partner'.($partner->tier === null ? '' : ' ('.ucfirst($partner->tier).')'),
                    'employer_name' => $partner->name,
                    'description' => $partner->description,
                    'url' => $partner->profileUrl,
                    'raw' => ['slug' => $partner->slug, 'tier' => $partner->tier, 'website' => $partner->website, 'regions' => $partner->regions],
                ],
            );

            if ($signal->wasRecentlyCreated) {
                $signal->update(['posted_at' => now()]);
            }

            return [$company, $created, $signal];
        }));

        if ($signal->wasRecentlyCreated) {
            $run?->increment('signals_found');
        }

        if (! $created) {
            if ($signal->wasRecentlyCreated) {
                $company->recordEvent($company->pipeline_step, PipelineOutcome::Ok, 'Listed as a Laravel Partner', ['signal_id' => $signal->id]);
                $this->rescore->rescoreIfFinished($company);
            }

            return false;
        }

        $run?->increment('companies_created');
        $company->recordEvent(PipelineStep::RegistrySearch, PipelineOutcome::Ok, 'Discovered on laravel.com/partners', ['signal_id' => $signal->id]);
        $this->advance->run($company);

        return true;
    }
}
