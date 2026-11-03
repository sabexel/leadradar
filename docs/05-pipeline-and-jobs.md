# 05 — Pipeline & jobs

## Principles

1. **A job = one outbound call.** Load model → call one service method → persist via an Action →
   call `AdvanceLeadPipelineAction::run($company)`.
2. **Local logic never runs in a job.** Classification, scoring, dedupe and email-pattern generation run
   synchronously inside Actions.
3. **Idempotent.** Every job can run twice without duplicating rows (use `firstOrCreate`/upserts and unique keys).
4. **Short.** Each job finishes in < 20 s. `$timeout = 30`, `$tries = 3`, `$backoff = [30, 120, 600]`.
5. **Unique.** Implement `ShouldBeUnique` with `uniqueId()` = `"{class}:{company_id}:{step-arg}"` so the
   same step is never queued twice.
6. **Rate limited.** Use `RateLimited` job middleware named per service (`companies_house`, `brave`,
   `millionverifier`, `reed`, `adzuna`) and `WithoutOverlapping("crawl:{domain}")` for crawler jobs.
7. **Caps.** If `ApiUsageLimiter` throws `DailyCapReachedException`, the job calls `$this->release(next day 06:00)`
   and the company stays at its current step (no failure, no loss).

## Queues

| Queue | Jobs |
|---|---|
| `sources` | FetchReedJobsJob, FetchReedJobDetailJob, FetchAdzunaJobsJob |
| `registry` | SearchCompaniesHouseJob, FetchCompanyProfileJob, FetchCompanyOfficersJob |
| `web` | FindCompanyWebsiteJob, CrawlCompanyWebsiteJob, FindLinkedInProfileJob |
| `verify` | VerifyEmailJob |

Worker command (run by the scheduler, see `08`):
`php artisan queue:work --queue=registry,verify,web,sources --stop-when-empty --max-time=50 --tries=3`
(Order matters: finish enriching existing companies before pulling more signals.)

## State machine — `AdvanceLeadPipelineAction`

```
discovered
  └─ suppressed? ───────────────────────────────► status=suppressed (stop)
  └─ registry_search    → SearchCompaniesHouseJob
       no match / ambiguous ─────────────────────► rejected(not_on_registry)
  └─ registry_profile   → FetchCompanyProfileJob
       not active / dormant / not ltd|llp ───────► rejected(not_active | dormant | wrong_legal_form)
  └─ registry_officers  → FetchCompanyOfficersJob
  └─ classify           (sync CompanyClassifier, first pass on SIC + name)
       recruiter ────────────────────────────────► rejected(recruiter)
  └─ find_website       → FindCompanyWebsiteJob   (skip if website known)
       none found ───────────────────────────────► rejected(no_website)
  └─ crawl              → CrawlCompanyWebsiteJob (home first; each result queues the next page
                          until page cap or no more candidate links)
       then: classify again with crawl signals; offshore ──► rejected(offshore)
  └─ build_email_candidates (sync)
  └─ verify_emails      → VerifyEmailJob (crawled person emails first, then generated candidates;
                          stop for a person at the first `ok`; also verify contact + careers inboxes)
  └─ find_linkedin      → FindLinkedInProfileJob for Founder 1 and Founder 2
  └─ qualify            (sync QualifyCompanyAction → gates + score)
       ─────────────────────────────────────────► qualified (score ≥ threshold) | rejected(reason)
  └─ done
```

Each transition writes a `PipelineEvent`. A company with a failed job (tries exhausted) gets
`status=error`; the dashboard offers **Retry step**.

## Jobs (thin) — example shape

```php
final class FetchCompanyOfficersJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public int $tries = 3; public int $timeout = 30; public array $backoff = [30, 120, 600];

    public function __construct(public int $companyId) { $this->onQueue('registry'); }

    public function uniqueId(): string { return "officers:{$this->companyId}"; }

    public function middleware(): array { return [new RateLimited('companies_house')]; }

    public function handle(CompaniesHouseService $ch, ApplyOfficersAction $apply,
                           AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::findOrFail($this->companyId);
        $officers = $ch->getOfficers($company->registration_number);   // the ONE outbound call
        $apply->run($company, $officers);                               // sync, local
        $advance->run($company->refresh());                             // decides & dispatches next
    }
}
```

## Discovery run — `leadradar:discover`

Scheduled daily at **06:10 UK time** (and runnable from the dashboard):
1. Create `ScrapeRun`.
2. If today's `qualified` count ≥ `daily_qualified_target` → mark run `stopped_target_reached`, exit.
3. Dispatch `FetchReedJobsJob` (page 1) and `FetchAdzunaJobsJob` (page 1) for each keyword/country.
   Each page job dispatches the next page only while (a) results are non-empty, (b) ads are within
   `signal.max_age_days`, and (c) the run is still below target.
4. `IngestJobAdAction` (sync, per ad): skip recruiter employers (`RecruiterDetector`), upsert `Signal`,
   match/create `Company` by normalised name (and domain when known), skip if suppressed,
   then `AdvanceLeadPipelineAction::run()`.
5. A scheduled `leadradar:close-runs` (every 15 min) finalises counts when the queue for the run is empty.

## Re-scoring

`leadradar:rescore` recomputes scores for all non-rejected companies (run after changing scoring rules).
Pure local — no jobs.
