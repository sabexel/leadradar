# 06 — Qualification gates & 0–10 score

`QualifyCompanyAction` runs synchronously at the `qualify` step and whenever `leadradar:rescore` runs.
It first applies **hard gates**. Any failed gate → `rejected` with that `RejectReason`, score still
computed (for analysis) but the lead never shows as qualified.

## Hard gates (all must pass)

| Gate | Rule | Reject reason |
|---|---|---|
| Registry | UK: matched on Companies House | `not_on_registry` |
| Active | `company_status = active`, not liquidated | `not_active` |
| Legal form | UK: `ltd`, `llp` (also `plc` allowed) — never sole trader/partnership | `wrong_legal_form` |
| Not dormant | `accounts_category` ≠ `dormant` | `dormant` |
| Not a recruiter | `company_type` ≠ `recruiter` | `recruiter` |
| Not offshore | `company_type` ≠ `offshore` | `offshore` |
| Has website | `website` not null | `no_website` |
| Contactable | ≥ 1 email with status `ok`, or a founder email with `catch_all` **and** a crawled (not generated) origin | `no_valid_email` |
| Laravel evidence | ≥ 1 strong signal, or a Laravel job ad from this company | `no_laravel_evidence` |
| Not suppressed | domain / email / company number not in `suppressions` | `suppressed` |

## Score (0–10), stored in `score` with a per-line `score_breakdown`

| Criterion | Points | Rule |
|---|---|---|
| **Laravel evidence** | max **2.5** | Laravel job ad from this company +1.5 · Laravel on services/case studies/careers +1.0 · Laravel Partner +1.0 · own site runs Laravel/Livewire/Inertia +0.5 (cap 2.5) |
| **Timing (signal freshness)** | max **2.0** | Newest Laravel ad ≤ 7 days 2.0 · ≤ 14 days 1.5 · ≤ 30 days 1.0 · older/none 0 |
| **Company fit** | max **2.0** | Agency 1.0 / direct client 0.5 · accounts `micro-entity` or `small` +0.5 · incorporated ≥ 2 years +0.5 |
| **Decision-maker reach** | max **2.5** | Founder 1 email `ok` +1.5 (`catch_all` +0.5) · Founder 1 LinkedIn found +0.5 · Founder 2 identified with any email +0.5 |
| **Company inboxes** | max **1.0** | Contact email `ok` +0.5 · careers email found +0.5 |

Total is capped at 10.0 and rounded to one decimal.

**Qualified** = all gates pass **and** score ≥ `qualify_threshold` (default **6.0**).
Below threshold → `rejected(below_threshold)`; these stay visible under a "Near misses" filter
(score 4.0–5.9) because they can be fixed manually.

## Rating labels on the dashboard

| Score | Label | Colour |
|---|---|---|
| 8.0–10 | Excellent | green |
| 6.0–7.9 | Good | blue |
| 4.0–5.9 | Near miss | amber |
| < 4.0 | Weak | grey |

## Tests required

- One unit test per criterion line, plus boundary tests (7 vs 8 days old, 5.9 vs 6.0).
- A feature test with a fixture company for each reject reason.
