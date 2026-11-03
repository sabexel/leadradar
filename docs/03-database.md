# 03 — Database (MySQL)

All tables have `id` (bigint), `created_at`, `updated_at` unless stated. Use PHP enums cast on models.

## companies

| Column | Type | Notes |
|---|---|---|
| name | string | As found in the signal |
| legal_name | string null | From registry |
| normalised_name | string, index | Lowercase, no "ltd/limited/llp", no punctuation — used for dedupe |
| website | string null | Canonical origin `https://example.co.uk` |
| domain | string null, unique | `example.co.uk` — primary dedupe key once known |
| country | char(2) | `GB`, `NL`, `US` |
| location | string null | Town/city, for display |
| registered_address | json null | Raw registry address |
| registration_number | string null, index | e.g. Companies House number (keep leading zeros) |
| registry | string null | `companies_house`, `kvk`, `opencorporates` |
| company_status | string null | Registry status (`active`, `dissolved`…) |
| company_kind | string null | Registry type (`ltd`, `llp`, `private-unlimited`…) |
| incorporated_on | date null | |
| sic_codes | json null | |
| accounts_category | string null | e.g. `micro-entity`, `small`, `full`, `dormant` |
| contact_email_id | FK email_addresses null | Company general inbox |
| careers_email_id | FK email_addresses null | |
| company_type | enum CompanyType | `agency`, `direct_client`, `recruiter`, `offshore`, `unknown` |
| laravel_evidence | json null | List of `{type, detail, url}` |
| offshore_evidence | json null | Same shape |
| status | enum LeadStatus | See below |
| pipeline_step | enum PipelineStep | Current/next step |
| reject_reason | enum RejectReason null | |
| score | decimal(3,1) null | 0.0–10.0 |
| score_breakdown | json null | Per-criterion points (see `06`) |
| outreach_status | enum OutreachStatus | `not_contacted`, `contacted`, `replied`, `not_interested`, `client` |
| contacted_at | datetime null | |
| qualified_at | datetime null, index | |
| notes | text null | Manual notes |

**Indexes:** `(status, score)`, `(country, status)`, `qualified_at`, `normalised_name`.

## people (founders/directors)

| Column | Type | Notes |
|---|---|---|
| company_id | FK | cascade delete |
| position | tinyint | 1 = Founder 1, 2 = Founder 2 (max 2 shown; more may be stored) |
| first_name, last_name | string | Parsed from registry `"SURNAME, Firstname Middle"` format |
| preferred_name | string null | e.g. "Josh" — manual |
| role | string | `director`, `llp-member`, `founder` |
| appointed_on | date null | |
| resigned_on | date null | Resigned people are stored but never selected as Founder 1/2 |
| country_of_residence | string null | From registry — used by offshore check |
| linkedin_url | string null | |
| linkedin_source_url | string null | Search result it came from |
| email_id | FK email_addresses null | Best email for this person |
| source | string | `companies_house`, `website`, `manual` |

## email_addresses

| Column | Type | Notes |
|---|---|---|
| company_id | FK | |
| person_id | FK null | Null for company inboxes |
| email | string | Lowercased |
| kind | enum EmailKind | `contact`, `careers`, `person`, `other` |
| origin | string | `crawled` (found on site), `generated` (pattern), `manual`, `import` |
| source_url | string null | Page it was found on |
| verification_status | enum EmailVerificationStatus | `unverified`, `ok`, `catch_all`, `unknown`, `invalid`, `disposable`, `error` |
| verification_raw | json null | Raw verifier response |
| verified_at | datetime null | |

Unique `(company_id, email)`.

## signals (why we found the company)

| Column | Type | Notes |
|---|---|---|
| company_id | FK null | Null until matched |
| source | enum SignalSource | `reed`, `adzuna`, `manual`, `import`, `laravel_partners` |
| external_id | string | Unique per source |
| title | string | Job title |
| employer_name | string | As posted |
| location | string null | |
| description | text null | |
| url | string null | |
| posted_at | datetime null | |
| raw | json | Full payload |

Unique `(source, external_id)`.

## crawled_pages

`company_id`, `url` (unique per company), `http_status`, `fetched_at`, `content_hash`, `page_type`
(`home`, `contact`, `about`, `team`, `careers`, `services`, `other`), `extracted` (json).
**Do not store full HTML** — store extracted facts only (keeps DB small, avoids hoarding content).

## pipeline_events

`company_id`, `step` (enum PipelineStep), `outcome` (`ok`, `skipped`, `failed`, `rejected`), `message`,
`context` (json), `created_at` only.

## scrape_runs

`started_at`, `finished_at`, `trigger` (`schedule`, `manual`), `signals_found`, `companies_created`,
`qualified_count`, `rejected_count`, `status` (`running`, `finished`, `stopped_target_reached`, `failed`).

## api_usages

`service` (`companies_house`, `reed`, `adzuna`, `millionverifier`, `brave`, `crawler`), `endpoint`,
`status_code`, `duration_ms`, `cost_units` (decimal — credits/queries), `date` (date, index),
`created_at` only. Used for daily caps and the Runs page.

## suppressions

`type` (`domain`, `email`, `company_number`), `value` (unique per type), `reason`
(`already_contacted`, `opted_out`, `not_interested`, `competitor`, `manual`), `created_at`.
Checked before any enrichment spend.

## settings (key/value)

`key` (unique), `value` (json). Seed defaults:

| key | default |
|---|---|
| `daily_qualified_target` | 50 |
| `qualify_threshold` | 6.0 |
| `cap.millionverifier.per_day` | 150 |
| `cap.brave.per_day` | 150 |
| `cap.companies_house.per_5min` | 500 (official limit is 600/5 min — keep headroom) |
| `cap.crawler.pages_per_company` | 6 |
| `search.keywords` | `["laravel"]` |
| `search.countries` | `["GB"]` |
| `signal.max_age_days` | 30 |

## Enums

```php
enum LeadStatus: string { case Discovered='discovered'; case Enriching='enriching';
  case Qualified='qualified'; case Rejected='rejected'; case Suppressed='suppressed'; case Error='error'; }

enum PipelineStep: string { case RegistrySearch='registry_search'; case RegistryProfile='registry_profile';
  case RegistryOfficers='registry_officers'; case Classify='classify'; case FindWebsite='find_website';
  case Crawl='crawl'; case BuildEmailCandidates='build_email_candidates'; case VerifyEmails='verify_emails';
  case FindLinkedIn='find_linkedin'; case Qualify='qualify'; case Done='done'; }

enum CompanyType: string { case Agency='agency'; case DirectClient='direct_client';
  case Recruiter='recruiter'; case Offshore='offshore'; case Unknown='unknown'; }

enum RejectReason: string { case NotOnRegistry='not_on_registry'; case NotActive='not_active';
  case WrongLegalForm='wrong_legal_form'; case Dormant='dormant'; case Recruiter='recruiter';
  case Offshore='offshore'; case NoWebsite='no_website'; case NoValidEmail='no_valid_email';
  case NoLaravelEvidence='no_laravel_evidence'; case Suppressed='suppressed'; case BelowThreshold='below_threshold'; }

enum EmailVerificationStatus: string { case Unverified='unverified'; case Ok='ok'; case CatchAll='catch_all';
  case Unknown='unknown'; case Invalid='invalid'; case Disposable='disposable'; case Error='error'; }

enum OutreachStatus: string { case NotContacted='not_contacted'; case Contacted='contacted';
  case Replied='replied'; case NotInterested='not_interested'; case Client='client'; }
```
