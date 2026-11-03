# START HERE (for the owner)

## 1. Before the first Claude Code session (≈ 30 minutes)

Register these keys. Store them in a password manager; they go into `.env` only.

| Service | Where | Cost |
|---|---|---|
| Companies House API | developer.company-information.service.gov.uk → create application → REST API key | Free |
| Reed API | reed.co.uk/developers/jobseeker | Free |
| Adzuna API | developer.adzuna.com → register | Free tier |
| Brave Search API | api-dashboard.search.brave.com | Free tier (~2,000 queries/month), then ~ $3 per 1,000 |
| MillionVerifier | millionverifier.com → API | Paid credits (start with the smallest pack) |

Also: create an empty **public** GitHub repo `leadradar`, and confirm your **Hostinger plan** (shared/cloud
with SSH, or VPS). Write the plan type into `docs/brain/BRAIN.md → Open questions`.

## 2. Put these files in the repo

Copy `CLAUDE.md`, `README.md` and the whole `docs/` folder into the repo root and commit:
`git commit -m "docs: project specification and brain"`.

## 3. First prompt to Claude Code

> Read CLAUDE.md, docs/brain/BRAIN.md and docs/10-build-plan.md. Then start P0.1 and work through P0
> in order. After each checklist item, update the brain files as CLAUDE.md requires and commit.
> Stop after P0 and summarise what was done.

## 4. Every later session

> Read CLAUDE.md and docs/brain/BRAIN.md, then continue from "Next step". Stop at the end of the current phase.

## 5. Rules for you

- Don't let it start P4 before P3 is live.
- Sending stays at 5–15 emails/day from Outlook. LeadRadar finds and checks leads; it doesn't send.
- If Claude Code proposes changing an architecture rule, make it record the decision in `BRAIN.md` first.
