# 02 — Architecture

## Stack

- Laravel (latest stable), PHP 8.3+
- Official **React starter kit**: Inertia + React + TypeScript + Tailwind + shadcn/ui
- MySQL 8
- Queue: `database` driver. Cache: `database`. Scheduler: `php artisan schedule:run` via cron.
- HTTP: Laravel `Http` client (Guzzle). HTML parsing: `symfony/dom-crawler` + `symfony/css-selector`.
- Tests: Pest, `Http::fake()`, sanitised JSON/HTML fixtures in `tests/Fixtures`.

## Layering (who may call whom)

```
Controllers (Inertia)      → Actions, Queries, Models          (no Http::, no business rules)
Scheduler / Commands       → dispatch Source jobs
Jobs (outbound only)       → ONE Service method → store result → AdvanceLeadPipelineAction
Actions (local, sync)      → Models, local Services (scoring, classification, patterns, dedupe)
Services (external)        → Http:: only here, return DTOs, log ApiUsage, throw typed exceptions
Services (local)           → pure logic, no I/O except models where stated
```

**The rule:** if a step needs the network, it is a **Job** that calls a **Service**.
If it doesn't, it is an **Action** or local **Service** called synchronously.

## Folder structure

```
app/
├── Actions/
│   ├── IngestJobAdAction.php            # job ad → dedupe → Company (+ Signal)
│   ├── AdvanceLeadPipelineAction.php    # state machine: decides & dispatches next job
│   ├── ApplyCompaniesHouseResultAction.php
│   ├── ApplyCrawlResultAction.php
│   ├── BuildFounderEmailCandidatesAction.php
│   ├── QualifyCompanyAction.php         # gates + score → qualified / rejected
│   └── ImportContactedCsvAction.php     # suppression import
├── Console/Commands/
│   ├── RunDiscoveryCommand.php          # leadradar:discover
│   └── RescoreCompaniesCommand.php      # leadradar:rescore
├── DTOs/
│   ├── CompaniesHouse/{CompanySearchResult,CompanyProfile,Officer}.php
│   ├── JobBoards/JobAdData.php          # shared by Reed + Adzuna
│   ├── Crawl/CrawlResult.php
│   ├── Search/SearchResult.php
│   └── Verification/EmailVerificationResult.php
├── Enums/
│   ├── LeadStatus.php  PipelineStep.php  CompanyType.php  SignalSource.php
│   ├── EmailKind.php   EmailVerificationStatus.php  OutreachStatus.php  RejectReason.php
├── Exceptions/
│   ├── ExternalServiceException.php  RateLimitedException.php  DailyCapReachedException.php
├── Http/Controllers/
│   ├── DashboardController.php  CompanyController.php  RunController.php
│   ├── SettingsController.php  ExportController.php  ImportController.php  SuppressionController.php
├── Jobs/
│   ├── Sources/
│   │   ├── FetchReedJobsJob.php          # one search page
│   │   ├── FetchReedJobDetailJob.php     # one job detail
│   │   └── FetchAdzunaJobsJob.php        # one search page
│   └── Enrichment/
│       ├── SearchCompaniesHouseJob.php
│       ├── FetchCompanyProfileJob.php
│       ├── FetchCompanyOfficersJob.php
│       ├── FindCompanyWebsiteJob.php
│       ├── CrawlCompanyWebsiteJob.php    # one page per job (see 05)
│       ├── VerifyEmailJob.php            # one email per job
│       └── FindLinkedInProfileJob.php    # one person per job
├── Models/
│   ├── Company.php  Person.php  EmailAddress.php  Signal.php
│   ├── CrawledPage.php  PipelineEvent.php  ScrapeRun.php  ApiUsage.php
│   ├── Suppression.php  Setting.php  User.php
├── Queries/
│   └── CompanyIndexQuery.php            # filters for the dashboard table
├── Services/
│   ├── CompaniesHouse/CompaniesHouseService.php
│   ├── JobBoards/ReedService.php
│   ├── JobBoards/AdzunaService.php
│   ├── Verification/MillionVerifierService.php
│   ├── Search/BraveSearchService.php
│   ├── Crawler/WebsiteCrawlerService.php
│   ├── Crawler/LaravelSignalDetector.php     # local: analyse HTML/headers
│   ├── Crawler/OffshoreSignalDetector.php    # local
│   ├── Crawler/EmailExtractor.php            # local
│   ├── Crawler/RobotsTxtGuard.php            # external (fetches robots.txt, cached)
│   ├── Classification/CompanyClassifier.php  # local
│   ├── Classification/RecruiterDetector.php  # local
│   ├── Email/EmailPatternService.php         # local
│   ├── Scoring/LeadScoringService.php        # local
│   ├── Dedupe/CompanyMatcher.php             # local: name/domain normalisation
│   └── Support/ApiUsageLimiter.php           # daily caps + per-minute limits
resources/js/
├── pages/
│   ├── dashboard.tsx
│   ├── companies/index.tsx   companies/show.tsx
│   ├── runs/index.tsx        settings/index.tsx   imports/index.tsx
├── components/leads/
│   ├── score-badge.tsx  status-badge.tsx  verification-badge.tsx
│   ├── company-filters.tsx  score-breakdown.tsx  pipeline-timeline.tsx  stat-card.tsx
└── types/leads.d.ts
tests/
├── Feature/ (pipeline, controllers, exports)
├── Unit/    (detectors, classifier, scoring, patterns, matcher)
└── Fixtures/ (companies-house/, reed/, adzuna/, millionverifier/, brave/, html/)
```

## End-to-end flow

```
Scheduler (daily) ─► FetchReedJobsJob / FetchAdzunaJobsJob  (per page)
                          │
                          ▼  IngestJobAdAction (sync): recruiter filter, dedupe, Company + Signal
                          │
             AdvanceLeadPipelineAction decides next step for each Company:
                          │
   discovered ─► SearchCompaniesHouseJob ─► FetchCompanyProfileJob ─► FetchCompanyOfficersJob
                          │                     (gate: active Ltd/LLP, not dormant)
                          ▼
   CompanyClassifier (sync): agency / direct_client / recruiter / offshore
                          ▼
   FindCompanyWebsiteJob (if no website) ─► CrawlCompanyWebsiteJob ×N pages
                          ▼  ApplyCrawlResultAction (sync): emails, Laravel + offshore signals
   BuildFounderEmailCandidatesAction (sync) ─► VerifyEmailJob ×(until first ok / cap)
                          ▼
   FindLinkedInProfileJob ×(founder 1, founder 2)
                          ▼
   QualifyCompanyAction (sync): gates + score ─► qualified | rejected(reason)
```

Every step writes a `PipelineEvent` row (step, outcome, message) so the dashboard can show a timeline
and failures can be retried from the UI.
