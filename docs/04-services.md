# 04 — Services

Every external service:
- Lives in `app/Services/<Area>/`, is registered as a singleton, and reads config from `config/services.php`.
- Uses `Http::baseUrl()->timeout(15)->retry(2, 500, throw: false)` (no retries on 4xx except 429).
- Checks `ApiUsageLimiter` **before** the call and logs an `ApiUsage` row **after** it.
- Returns **DTOs**, never raw arrays. Throws `RateLimitedException` (429 / cap reached) or
  `ExternalServiceException` (5xx, malformed). Jobs decide whether to `release()` or fail.
- Has a matching fixture folder in `tests/Fixtures/<service>/` and a unit test using `Http::fake()`.

> ⚠️ API details below were checked on 2026-11-03. **Before implementing each service, open its official
> docs and confirm endpoints, field names and limits.** If anything differs, follow the docs and record the
> difference in `BRAIN.md → Decisions`.

---

## CompaniesHouseService (UK registry) — free

- Base URL: `https://api.company-information.service.gov.uk`
- Auth: HTTP Basic — API key as **username**, empty password.
- Rate limit: **600 requests per 5 minutes** per key. Cap at 500 (setting).

| Method | Endpoint | Returns |
|---|---|---|
| `searchCompanies(string $name, int $limit = 5): Collection<CompanySearchResult>` | `GET /search/companies?q=` | number, title, status, type, address snippet, date of creation |
| `getProfile(string $number): CompanyProfile` | `GET /company/{number}` | status, type, SIC codes, registered address, accounts (incl. last accounts type), date of creation, `has_been_liquidated` |
| `getOfficers(string $number): Collection<Officer>` | `GET /company/{number}/officers` | name, officer_role, appointed_on, resigned_on, country_of_residence, nationality |

Rules:
- Pick the best search match with `CompanyMatcher` (normalised-name similarity ≥ 0.85 **and** status `active`).
  If two candidates tie, prefer the one whose postcode/town matches the signal location. If still ambiguous →
  reject with `not_on_registry` and log candidates in the event context (manual review).
- Officers: keep `director` and `llp-member` roles; ignore secretaries and corporate officers;
  Founder 1/2 = earliest-appointed **active** directors.
- Names arrive as `"SURNAME, Firstname Middle"` → parse to first/last; title-case.

## ReedService (UK job ads) — free key

- Base URL: `https://www.reed.co.uk/api/1.0`
- Auth: HTTP Basic — API key as **username**, empty password.
- Rate limits: not documented → self-limit to 1 request/second.

| Method | Endpoint | Notes |
|---|---|---|
| `search(string $keywords, int $skip = 0, int $take = 100): Collection<JobAdData>` | `GET /search` | Pass `postedByDirectEmployer=true` to exclude recruitment agencies at source |
| `getJob(int $jobId): JobAdData` | `GET /jobs/{jobId}` | Full description, external URL |

## AdzunaService (UK job ads) — free key

- Search: `GET https://api.adzuna.com/v1/api/jobs/gb/search/{page}?app_id=&app_key=&what=laravel&results_per_page=50&max_days_old=30`
- Map `company.display_name`, `title`, `location.display_name`, `description`, `redirect_url`, `created`, `id`.
- Adzuna includes recruiter-posted ads → always run `RecruiterDetector` on the employer name.
- Confirm current free-tier limits in Adzuna's docs and set the daily cap accordingly.

## MillionVerifierService (email verification) — paid credits

- Single verify: `GET https://api.millionverifier.com/api/v3/?api={key}&email={email}&timeout=10`
  (confirm against current docs).
- Map the result to `EmailVerificationStatus`: `ok`, `catch_all`, `unknown`, `invalid`, `disposable`, else `error`.
- Method: `verify(string $email): EmailVerificationResult` (status, quality/sub-result, raw).
- Optional: `credits(): int` (balance), shown on Settings page.
- Cost control: 1 credit per call; respect `cap.millionverifier.per_day`; **never re-verify** an email verified
  in the last 90 days.

## BraveSearchService (website + LinkedIn lookup) — free tier then pay-as-you-go

- `GET https://api.search.brave.com/res/v1/web/search?q=...&country=GB&count=10`
- Header: `X-Subscription-Token: {key}`, `Accept: application/json`.
- Free tier ≈ 2,000 queries/month at 1 query/second (check current plan). At 50 leads/day you will need
  ~100–150 queries/day → expect to use the paid tier (≈ $3 per 1,000 queries). Cap via setting.

| Method | Query | Selection logic |
|---|---|---|
| `findCompanyWebsite(string $name, ?string $town): ?SearchResult` | `"{legal name}" {town}` | First result whose domain is **not** in the directory blocklist (Companies House, Endole, LinkedIn, Facebook, Indeed, Reed, Glassdoor, Yell, Clutch, Bloomberg, Crunchbase, etc.) **and** whose page title/snippet contains a significant token of the company name |
| `findLinkedInProfile(string $fullName, string $companyName): ?SearchResult` | `site:linkedin.com/in "{full name}" "{company}"` | Only accept `linkedin.com/in/...` URLs whose title contains both first and last name. Store the URL only — never fetch the LinkedIn page |

## WebsiteCrawlerService (company websites) — no API

- Method: `fetchPage(Company $company, string $url): CrawlResult` — **one page per call**.
- Uses `RobotsTxtGuard` (cached 24 h per domain) — skip disallowed paths.
- User-Agent: `LeadRadarBot/1.0 (+https://webwayout.co.uk/bot)`. No personal email in the UA.
- Per-domain politeness: ≥ 2 seconds between requests to the same domain (enforced via cache lock).
- Max `cap.crawler.pages_per_company` pages (default 6), same domain only, timeouts 10 s, max 2 MB body.
- Page discovery order: home → links whose text/URL matches `contact|about|team|people|careers|jobs|work-with-us|services|laravel|case-stud` → sitemap.xml fallback.
- Returns `CrawlResult`: status, final URL, page type, title, extracted emails, internal links, raw signals
  from the three local analysers below.

Local analysers (pure functions, unit-tested with HTML fixtures):

**`EmailExtractor`** — `mailto:` links, plain-text emails, and common obfuscations (`name [at] domain [dot] com`).
Keep only emails on the company's own domain (or a clear mail alias of it). Classify:
`hello|info|contact|enquiries|office|studio|team` → `contact`; `careers|jobs|recruitment|hr|hiring|work` → `careers`;
`firstname@`, `firstname.lastname@`, `f.lastname@` matching a known person → `person`.

**`LaravelSignalDetector`** → list of `{type, weight, detail}`:
| Signal | Weight |
|---|---|
| Word "Laravel" on services / case-study / careers page | strong |
| Laravel Partner badge/link (`laravel.com/partners`) | strong |
| Response cookies `laravel_session` or `XSRF-TOKEN` | medium (their own site runs Laravel) |
| Livewire assets (`livewire.js`, `wire:` attributes) or Inertia `data-page` root | medium |
| PHP framework mention list including Laravel | weak |

**`OffshoreSignalDetector`** → list of signals:
- Office/address text in India, Pakistan, Bangladesh, Ukraine, etc. combined with words like
  `delivery centre`, `offshore`, `nearshore`, `dedicated developers`, `hire developers`, `staff augmentation`.
- "Hire a Laravel developer from $X/hour" style pricing.
- Combined with Companies House officers whose `country_of_residence` are all outside the UK → **strong**.

## Local services (no network)

- **`CompanyMatcher`** — `normalise(name)`, `similarity(a, b)` (e.g. Jaro-Winkler or `similar_text`), `domainOf(url)`.
- **`RecruiterDetector`** — employer-name keywords (`recruit`, `resourcing`, `talent`, `staffing`, `search partners`,
  `people`, `careers ltd`…) + SIC codes `78100`, `78109`, `78200`, `78300`.
- **`CompanyClassifier`** — uses SIC codes, crawl signals and detectors:
  - SIC `62011/62012/62020/62090/63110/73110/74100` + agency words on site (`agency`, `studio`, `clients`, `our work`) → `agency`
  - Laravel product/in-house hiring without agency language → `direct_client`
  - Recruiter signals → `recruiter`; strong offshore signals → `offshore`; else `unknown`.
- **`EmailPatternService`** — `candidates(Person $p, string $domain): array` in this order:
  `first@`, `first.last@`, `firstlast@`, `f.last@`, `flast@`, `first_last@`, `last@`. Skip patterns if a
  `person` email for this person was already crawled. If the company already has a confirmed pattern from
  another person, try that pattern first.
- **`LeadScoringService`** — see `06-scoring-and-checks.md`.
- **`ApiUsageLimiter`** — `ensureAvailable(service)`, `record(service, cost)`; daily caps from settings and
  per-minute limits via `RateLimiter`.
