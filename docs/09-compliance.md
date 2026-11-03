# 09 — Compliance & crawling rules

> Not legal advice. These are the working rules for the build; the owner remains responsible for how leads are used.

## Email outreach law (why the gates exist)

- **UK (PECR):** unsolicited B2B email is allowed to *corporate subscribers* (Ltd, LLP, PLC) with a clear opt-out
  in every message. **Sole traders and partnerships count as individuals** and need prior consent →
  the `wrong_legal_form` gate.
- **Netherlands / USA (P4):** generally allowed to companies with an opt-out (US: CAN-SPAM rules — identify sender,
  physical address, working opt-out).
- **Germany / Italy:** prior consent required even for B2B → **never** add DE/IT to `search.countries`.

## UK GDPR (founder names and emails are personal data)

- Lawful basis: **legitimate interests** (B2B prospecting). Keep a short Legitimate Interests Assessment in
  `docs/LIA.md` (owner to write; template headings: purpose, necessity, balancing, safeguards).
- **Data minimisation:** store only the fields in `01-overview.md`. No phone numbers, no home addresses,
  no dates of birth (Companies House returns partial DOB — **do not store it**), no full page HTML.
- **Provenance:** every email/LinkedIn URL stores `origin` and `source_url`.
- **Opt-out / erasure:** "Suppress & delete" action on a lead removes the person rows and adds the domain/email
  to `suppressions` so it is never re-collected.
- **Retention:** companies with `outreach_status = not_contacted` and no activity for 12 months are pruned
  (`model:prune`).
- First outreach email should say where the contact details came from (e.g. "found via Companies House and your website").

## Crawling rules

- Obey `robots.txt` (including `Crawl-delay`). Skip the site if the home page is disallowed.
- Identify the bot: `LeadRadarBot/1.0 (+https://webwayout.co.uk/bot)` — publish a short page at that URL
  explaining the bot and how to opt out.
- ≤ 6 pages per company, ≥ 2 s between requests to one domain, no login pages, no forms, no JS execution.
- **No LinkedIn fetching, no Google scraping.** Use official APIs (Companies House, Reed, Adzuna, Brave Search).
- Respect each API's terms and rate limits; cache results (don't re-query the same company within 30 days).

## Public repo hygiene

- `.env`, exports, imports, database dumps and `storage/` are git-ignored.
- Fixtures use invented companies/people (e.g. "Example Digital Ltd", "Jane Example").
- README states the tool's purpose and the rules above.
