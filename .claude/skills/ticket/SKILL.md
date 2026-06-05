---
name: ticket
description: Read, triage and close support tickets end-to-end in one session via the admin support API. Use when the user wants to look at support tickets, work on a reported bug/feature, or reply to / close a ticket after a delivery.
argument-hint: "[ticket-id]"
allowed-tools: "Read, Write, Edit, Grep, Glob, Bash, Skill"
---

# Support Ticket Workflow

Drive a support ticket from **reading → triage → (optional) delivery → reply/close**,
all in one session. Backed by `tools/support-cli/support-cli.php` (admin support API).

`$ARGUMENTS` = an optional ticket id. No id → list open tickets first.

## Core rules (non-negotiable)
- **Writes are gated.** `reply`, `set-status`, `set-priority` send a **real e-mail**
  to the ticket author. NEVER run them with `--confirm` until the user has seen the
  exact text/status and explicitly approved. Default to the DRY-RUN (no `--confirm`)
  to show the user what would be sent.
- **One approval = one action.** Approval to send a reply does not authorize a status
  change, and vice-versa. Ask again for each write.
- **Privacy.** Tickets contain other users' data. Don't persist any ticket content
  (emails, screenshots, trade data) into memory files. Keep it in-session.
- **Scope discipline.** If a ticket is out of scope or "later", write it to
  `docs/evolutions.md` (Support section) and stop — do not start coding.

## Step 1 — Read
- No id: `php tools/support-cli/support-cli.php list --status=OPEN,IN_PROGRESS,WAITING_USER`
  Present the rows, ask which ticket to work on.
- With an id: `php tools/support-cli/support-cli.php show <id>`
  Read the full thread.

## Step 2 — Triage
Summarize the ticket and classify it: **SUPPORT**, **BUG**, or **FEATURE**.
Then propose the next action and **wait for the user's decision**:
- *Answer directly* (a support question, no code) → go to Step 4.
- *Implement* (actionable bug/feature) → go to Step 3.
- *Backlog* → append a note to `docs/evolutions.md` and stop.

## Step 3 — Deliver (only on explicit "go")
Hand off to the existing skills, in order — do not reinvent them:
1. `/tdd-feature` — tests first, then code, then refactor.
2. `/check-quality`, `/check-i18n`, `/audit-security`, `/audit-privacy`.
3. `/doc-feature` — French doc, written **before** any merge.
The user controls all git operations and each merge gate (local → develop, test → main).

## Step 4 — Close the loop (gated writes)
1. Draft the reply to the author (in their locale; concise, what changed / answer).
   Show it. **DRY-RUN first** (no `--confirm`).
2. On the user's explicit approval, send it:
   `php tools/support-cli/support-cli.php reply <id> --body="…" --confirm`
3. Propose the new status (e.g. `RESOLVED`). On explicit approval:
   `php tools/support-cli/support-cli.php set-status <id> RESOLVED --confirm`
4. Confirm to the user what was sent.

## Notes
- Read commands (`list`, `show`) are unrestricted and safe to run anytime.
- Add `--json` to any command when you need to parse the raw payload.
- Config lives in `tools/support-cli/.env` (gitignored). If it's missing, tell the
  user to copy `.env.example` and fill in an ADMIN account — don't invent credentials.
- Statuses: `OPEN | IN_PROGRESS | WAITING_USER | RESOLVED | CLOSED`.
  Priorities: `LOW | NORMAL | HIGH`.
