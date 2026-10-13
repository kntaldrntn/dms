# CLAUDE.md

Rules for working in this repository. Keep this file short; procedures live in the
`senior-dev-workflow` skill and project state lives in `documentation.md`.

## Before you start

1. Read `documentation.md` (architecture, current state, known issues, next steps).
2. Skim `status.md` (the user-maintained feature/health tracker) for the area you will touch.
3. Run `git status`. The working tree often contains uncommitted user work — never revert,
   reset, stash, or overwrite it.
4. For non-trivial work, follow the `/senior-dev-workflow` skill.

## Project

Internal Document Management / Tracking System (DMS/DTS) for the City of San Fernando:
barcode intake with AI-assisted extraction, department routing with custody trails,
City Administrator referral slips, City Mayor (OCM) certificate-signed PDF approvals,
and an SLA/delinquency dashboard.

Stack: Laravel 12 (PHP ^8.2) + Inertia 2 + Vue 3 (TypeScript, `<script setup>`) +
Tailwind 3 + radix-vue/shadcn-style UI, Vite 6, Pest 3. MySQL locally; tests use
in-memory SQLite. Details in `documentation.md`.

## Architecture rules

- **Controllers stay thin.** Validation + authorization go in a `FormRequest`
  (`app/Http/Requests`); business logic goes in `app/Services`.
- **Every mutation of an existing document goes through `DocumentWorkflow::mutate()`**
  (row locks, policy check, `workflow_version` stale-form check, deadlock retry). Forms
  that change a document must submit `workflow_version`.
- **Authorization lives in `DocumentPolicy`** (registered in `AppServiceProvider`).
  Role gates use the `role:` middleware; processing routes use `working_hours`.
- Custody changes use `DocumentWorkflow::release()` + `receive()` so the trail is preserved.
  Never rewrite or delete existing `document_trails` rows.
- Resolve offices by department **code** (`REC`, `ADM`, `OCM`) via `DocumentWorkflow::office()`,
  never by hard-coded ID.
- Multi-step writes run inside a transaction; delete any file written before a failure.
- Use `Asia/Manila` for workflow timestamps, consistent with existing code.
- Frontend pages live in `resources/js/pages/<feature>/`; reuse `components/ui/*` before
  adding new primitives. Use Ziggy `route()` for URLs.

## Code standards

- Self-documenting names; comment only the *why* of non-obvious logic (locking, custody,
  SLA math, PDF coordinates).
- Validate all input server-side, including AI/LLM output, before using it.
- Handle errors explicitly: user-facing message + `Log::warning/error` with context;
  never swallow exceptions silently.
- No `console.log`, `dd()`, `dump()` in committed code. No unused imports, dead routes, or
  commented-out code. Remove what you replace.
- New dependencies need a clear justification; prefer what is already installed.
- New/changed tables need a new migration — never edit an applied migration.

## Security

- Secrets only in `.env` (git-ignored); read them through `config/*.php`, never `env()` in
  app code. Add new keys to `.env.example` with an empty value.
- Certificate and key material belongs in `storage/app/certificates/` only. Never place keys
  in `public/`, never commit them, never print them.
- Signed/sensitive files go on the `local` (private) disk and are served through an
  authorized controller action.
- Every new route needs `auth` + an explicit policy/role check.

## Accessibility (UI)

Labels tied to inputs, keyboard-reachable controls, visible focus, `aria-*` on custom
widgets/modals, text alternatives for icons and images, sufficient contrast, and errors
announced next to their field (`InputError`).

## Commands (verified 2026-10-13)

| Purpose | Command |
| --- | --- |
| Dev (server + queue + Vite) | `composer dev` |
| Tests | `./vendor/bin/pest` (or `php artisan test`) |
| Single test file | `./vendor/bin/pest tests/Feature/WorkflowReliabilityTest.php` |
| PHP style check | `./vendor/bin/pint --test` (fix: `./vendor/bin/pint <paths>`) |
| JS lint check | `npx eslint .` — note `npm run lint` **auto-fixes and writes files** |
| Format check | `npm run format:check` |
| Type check | `npx vue-tsc --noEmit` (currently broken, see documentation.md) |
| Build | `npm run build` |
| Regenerate Ziggy routes | `php artisan ziggy:generate` |

The repo has pre-existing Pint/Prettier violations. Format only the files you change;
do not mass-reformat without approval.

## Documentation & handoff

- Update `documentation.md` at meaningful milestones, before handing off, and before
  `/compact` on substantial work: what changed, why, files touched, checks run with real
  results, open issues, exact next steps.
- Record only verified facts; label assumptions. Never claim a check passed without running it.
- `status.md` is the user's tracker — update it only when asked or when completing an item it lists.

## Git

**Never commit, push, amend, reset, or discard changes unless the user explicitly authorizes it.**
