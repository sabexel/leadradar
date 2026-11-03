# 07 — Dashboard (Inertia + React + TypeScript)

Use the starter kit's layout, sidebar and shadcn/ui components. All pages behind auth.
Tables are server-paginated (Laravel paginator → Inertia props). Filters live in the URL query string
so a filtered view can be bookmarked.

## Sidebar

Dashboard · Leads · Imports · Runs · Settings

## 1. Dashboard (`/dashboard`)

**Stat cards (date range picker: today / 7 days / 30 days / custom):**
- Scraped (signals ingested)
- Companies discovered
- Qualified · Rejected · In progress · Errors
- Average score of qualified leads
- Verified founder emails (`ok`)
- Contacted / Replied (from outreach status)

**Charts:**
- Qualified per day (bar, last 30 days) vs daily target line
- Funnel: discovered → registry ok → website found → email verified → qualified
- Reject reasons (horizontal bar)
- Score distribution (histogram 0–10)
- Source split (Reed / Adzuna / Import)

**Panels:** last 5 runs (status, counts, duration) · API usage today vs caps · MillionVerifier credit balance.

## 2. Leads (`/companies`)

**Columns:** Score badge · Company · Type · Location · Reg. no · Founder 1 (name + verification badge) ·
Founder 2 · Contact email · Signal (job title + age) · Status · Outreach status · Qualified at.

**Filters:**
- Status (multi) · Outreach status (multi) · Company type (multi)
- Score range slider (0–10) and quick chips: Excellent / Good / Near miss
- Country · Source · Date qualified (range)
- Has verified founder email (toggle) · Has LinkedIn (toggle)
- Reject reason (when status = rejected)
- Free-text search (name, domain, founder, email)

**Sort:** score (default desc), qualified date, company name.

**Bulk actions:** Mark contacted · Mark not interested · Suppress domain · Re-run from step · Export CSV.

## 3. Lead detail (`/companies/{id}`)

- Header: name, score badge, status, type, outreach status selector, links (website, Companies House page).
- **Company card:** all required fields (name, website, location, registration number, contact email,
  careers email) with source + verification badge next to each email.
- **Founders (1 and 2):** name, role, appointed date, LinkedIn, email + verification status, "Re-verify" button,
  list of other tried candidates and their results.
- **Score breakdown:** each criterion with points and the evidence behind it.
- **Evidence:** Laravel signals (with URLs), offshore signals, signal(s) — job ad title, date, link.
- **Pipeline timeline:** `pipeline_events` in order; failed steps show a "Retry" button.
- **Notes** (editable).

## 4. Imports (`/imports`)

- Upload CSV / paste list of company names or domains → creates Companies with `source=import` and runs the
  pipeline (useful for Apollo exports and manual finds).
- **Suppression import:** upload the existing outreach sheet (CSV) → every domain/email becomes a suppression
  with reason `already_contacted`. Column mapping UI (choose which columns hold website and emails).

## 5. Runs (`/runs`)

Table of `scrape_runs` + per-service API usage per day (calls, cost units, errors) + failed jobs list with retry.
"Run discovery now" button (dispatches `leadradar:discover`).

## 6. Settings (`/settings`)

- Daily qualified target, qualify threshold, per-service caps, keywords, countries, signal max age.
- Key status per service: **configured / missing** (never display key values) + "Test connection" button
  (one cheap call, logged).

## Export format (CSV)

Same column order as the owner's outreach sheet so rows can be pasted straight in:

```
S.no, Company Name, Website, Location, Company House no, Company contact email, Company careers email,
Founder 1 First Name, Founder 1 Last Name, Founder 1 LinkedIn, Founder 1 email, Founder 1 email test,
Founder 2 First Name, Founder 2 Last Name, Founder 2 LinkedIn, Founder 2 email, Founder 2 email test,
Score, Company type, Signal, Signal URL
```

`email test` values: `Verified` (ok), `Catch-all`, `Unknown`, `Invalid`, `Generated` (unverified pattern).
Company numbers exported as text so leading zeros survive (`="07172996"` style or a quoted string).
