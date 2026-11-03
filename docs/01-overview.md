# 01 — Overview

## What LeadRadar is

A small, focused lead-research engine. Every day it:

1. **Finds signals** that a company needs Laravel help (mainly UK job ads that mention Laravel).
2. **Identifies the company** behind the signal and checks it against official registries.
3. **Enriches** it: website, contact and careers emails, founders/directors, LinkedIn URLs, founder emails.
4. **Verifies** emails and **rejects** anything that fails a hard check.
5. **Scores** each surviving lead **0–10** and shows everything on a dashboard.

It does **not** send email. Sending stays manual/low-volume (Outlook or Mailmeteor, 5–15 a day).
LeadRadar's job is to make each of those sends well-targeted.

## Who the leads are for

The owner runs **Webwayout Ltd**, a UK-registered Laravel studio, offering:
- **White-label overflow** to digital agencies (£200/day), and
- **Contract Laravel capacity** to companies with in-house Laravel products (from £250/day).

## Ideal lead (ICP)

| Attribute | Target |
|---|---|
| Geography | UK first. Netherlands and USA later (P4). |
| Legal form (UK) | **Active Ltd or LLP** on Companies House (PECR requirement — see `09-compliance.md`) |
| Type | Digital/web agency (best), or a company running its own Laravel product (good) |
| Size | Micro/small (≈2–50 staff). Proxy: Companies House accounts category |
| Laravel evidence | Laravel job ad, Laravel on their services/case-study/careers pages, or Laravel technical traces |
| Not | Recruitment agencies, offshore dev shops (competitors), dormant/dissolved companies, enterprise procurement |
| Contact | A named founder/director with a verified email |

## Data captured per company (owner's required fields)

| Field | Where it comes from |
|---|---|
| Company name | Signal (job ad) → normalised against Companies House |
| Company website | Brave Search lookup, or job ad / manual import |
| Company location | Companies House registered address + website |
| Registration number | Companies House (UK). US/NL later (P4) |
| Company contact email | Website crawl (`hello@`, `info@`, `contact@` …) |
| Company careers email | Website crawl (careers/jobs pages) |
| Founder 1 name | Companies House officers (active directors) |
| Founder 1 LinkedIn | Brave Search (`site:linkedin.com/in`) |
| Founder 1 email | Website crawl, or generated pattern |
| Founder 1 email test | MillionVerifier result (`ok`, `catch_all`, `unknown`, `invalid`, `disposable`) |
| Founder 2 (same fields) | Same sources |

## Constraints

- **Quality over volume.** Target: **50 qualified leads per day**. The scheduler stops when it gets there.
- **Cheap to run.** Free APIs where possible; paid ones (MillionVerifier, Brave Search) are capped per day.
- **Runs unattended on Hostinger.** No supervisor, no Redis — see `08-deployment-hostinger.md`.
- **Public GitHub repo.** It is also the owner's portfolio piece: clean architecture, tests, README.

## Success measures

- ≥ 80% of `qualified` leads have a founder email with status `ok`.
- < 5% of `qualified` leads turn out to be recruiters, offshore shops or dissolved companies on manual review.
- Pipeline runs daily without manual intervention for 2 weeks.
