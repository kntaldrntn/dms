# DMS — Project Documentation & Development Handoff

> Persistent handoff file. Read before continuing work; update at milestones, before
> handoff, and before `/compact`. Mark facts as **verified** only with evidence.
> Feature-level health is tracked separately by the user in `status.md`.

Last updated: 2026-10-14 (per-office seed accounts; referral slip AI pre-fill fix; earlier: multi-office custody, holders, delinquency scope, bell)

---

## 1. Overview

Internal Document Management / Tracking System for the City of San Fernando (Philippines).
Records staff register incoming documents by barcode (optionally AI-assisted from a scanned
first page), route them to departments, and every hand-off is logged in a custody trail.
Documents addressed to the City Administrator (`ADM`) go through a referral-slip approval;
documents for the City Mayor (`OCM`) get a visible stamp + certificate-based PDF signature.
A dashboard reports SLA/delinquency metrics by month and transaction category.

## 2. Verified stack (2026-10-13)

| Layer | Technology | Evidence |
| --- | --- | --- |
| Backend | Laravel 12.66.0, PHP 8.2.12 locally (CI uses 8.4) | `php artisan --version`, `php -v`, `.github/workflows` |
| Frontend | Inertia 2, Vue 3.5, TypeScript 5, Vite 6, Tailwind 3, radix-vue/shadcn-style components, lucide icons, notyf toasts | `package.json`, `components.json` |
| Routing helper | Ziggy (`resources/js/ziggy.js` is generated) | `composer.json`, CI step `ziggy:generate` |
| DB | MySQL (`DB_CONNECTION=mysql`, db `dms`) locally via XAMPP; SQLite `:memory:` for tests | `.env`, `phpunit.xml` |
| Queue / cache / session | `database` drivers | `.env` |
| AI | `openai-php/laravel` — `gpt-4o-mini` vision for intake extraction and OCM stamp placement | `DocumentAnalysisService`, `ReferralSlipController::findStampPlacement` |
| Client-side OCR/scan | tesseract.js, pdfjs-dist, @zxing (barcode) | `package.json` |
| PDF | barryvdh/laravel-dompdf (referral slips); setasign/fpdi-tcpdf + tecnickcom/tcpdf (OCM signed PDFs); smalot/pdfparser | `composer.json` |
| Tests | Pest 3 + pest-plugin-laravel; `RefreshDatabase` on Feature tests | `tests/Pest.php` |
| Node | v26.8.1 / npm 12.0.2 locally (CI uses Node 22) | `node -v` |

Unknown / unverified: production hosting, production DB engine/version, whether the queue
worker is used in production, mail configuration.

## 3. Architecture

Request flow: **route → middleware (`auth`, `verified`, `role:`, `working_hours`) → controller →
FormRequest (authorize + rules) → service → `DocumentWorkflow` → models/trails.**

### Key backend modules

| Path | Responsibility |
| --- | --- |
| `routes/web.php` | Document, referral, OCM, transaction, dashboard, and admin-registry routes. Processing routes are inside `working_hours` (Mon–Fri 08:00–17:00 Asia/Manila; system admins bypass). Admin registries require `role:system_administrator`. |
| `app/Services/DocumentWorkflow.php` | Core concurrency/custody engine. `mutate()` locks document + user, authorizes via policy, rejects inactive/completed docs (409), checks `workflow_version`, increments it, retries deadlocks up to 3×. Also `targets()`, `office(code)`, `release()`, `receive()`, `signer()`. |
| `app/Services/DocumentService.php` | Create/update/deactivate. On create, `ADM` target → custody moves REC → ADM with `requires_signature_from=city_administrator`; `OCM` target → custody moves to OCM with `requires_signature_from=city_mayor`; other targets → direct dispatch. Cleans up uploaded file on failure. |
| `app/Services/DispatchService.php`, `TransactionService.php` | Records dispatch (records every target in `released_to_offices`) and per-office inbox processing: each holding office ends or routes its own copy; the document completes when no open copy remains. |
| `app/Services/IncomingDocumentService.php` | Notification-bell data: open trail entries at the user's office with `received_by` null, filtered to the stages the user's role acts on. Shared lazily as `incoming`. |
| `app/Services/ReferralService.php` | Two-step ADM referral: `generate()` (City Administrator, slip only → `requires_signature_from=department_head`) and `sign()` (ADM Department Head, `signature_mode` electronic/physical → back to REC). OCM approval `approveOcm()` uses `PdfApprovalStampService`. |
| `app/Services/PdfApprovalStampService.php` | Stamps the uploaded PDF with a visible signature block and signs it with the certificate from `config/digital-certificate.php`; writes to `storage/app/private/signed-documents` (local disk). Original upload untouched. |
| `app/Http/Controllers/DocumentController.php` `analyze()` | Live intake AI: OCR text + thumbnail → OpenAI JSON; ADM/OCM ids resolved by code; `routingSuggestions()` validates the routing suggestions. |
| `app/Services/DocumentAnalysisService.php` | **Unused** older copy of the intake prompt (prompt reference: `basis.md`); not wired to any route. |
| `app/Policies/DocumentPolicy.php` | All document abilities: `viewAny`, `view`, `create`, `update`, `delete`, `dispatch`, `process`, `viewReferrals`, `viewOcmApprovals`, `refer`, `signReferral`, `viewReferralSlip`. Ownership = user's department equals document's current department. |
| `app/Http/Requests/*` | `SaveDocumentRequest`, `DispatchDocumentRequest`, `ProcessDocumentRequest`, `GenerateReferralRequest`, `SignReferralRequest`, `ApproveOcmDocumentRequest`, `AnalyzeDocumentRequest`, `DocumentMutationRequest` (base: requires `workflow_version`). |
| `app/Http/Controllers/DashboardController.php` | Invokable; SLA/delinquency/to-do metrics and drill-down. Delinquency is all-departments for `ALL_DEPARTMENT_ROLES`, otherwise the user's held documents. |
| `app/Http/Middleware` | `HandleRoles` (`role:` alias), `CheckWorkingHours`, `TrackUserActivity`, `HandleInertiaRequests` (shares `auth.user`, `auth.canViewReferrals`, lazy `incoming`). |
| `app/Models` | `Document` (computed `is_delayed`, `is_delinquent`; `holders()` = offices with an open copy; `scopeHeldBy`; `EXECUTIVE_STAGES`), `DocumentTrail`, `Department`, `User`, registries (`DocumentType`, `TransactionType`, `DocumentClassification`, `DeliveryMethod`, `PerformanceStandard`), `AuditLog`, `LoginHistory`. Active/inactive uses integer `state` (1/0). |
| `resources/views/referral-slips/print.blade.php` | Printable/PDF referral slip. |

### Roles (string column `users.role`)
`system_administrator`, `records_manager`, `city_administrator`, `city_mayor`,
`department_head`, `staff`.

### Department codes the code depends on
`REC` (Records), `ADM` (City Administrator), `OCM` (Office of the City Mayor). Exactly one
active department per code is required (`DocumentWorkflow::office()`).

### Custody model
Custody = open trail entries (`released_at IS NULL`). A multi-office dispatch gives every target office
its own copy (`Document::holders`). `documents.department_id` is only the primary office (first target or the
latest office a copy moved to). Inbox, `process`/`update` ownership, "Currently with", and the bell all use the trail.

### Frontend
`resources/js/app.ts` boots Inertia; pages resolve from `resources/js/pages/**`.
Feature pages: `documents/{Index,Show,Edit}`, `transactions/Index`, `referral-slips/Index`,
`ocm-approvals/Index`, `Dashboard`, admin registries (`departments`, `users`,
`document-types`, `transaction-types`, `document-classifications`, `delivery-methods`,
`performance-standards`), `settings/*`, `auth/*`. Shared: `components/` (app shell,
`RoutingModal`, `DeleteConfirmationModal`, `InputError`) and `components/ui/*`
(generated primitives; ESLint-ignored). Alias `@` → `resources/js`.

## 4. Setup & commands

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # then set DB_* (MySQL), OPENAI_API_KEY
php artisan migrate
php artisan storage:link                            # public/storage symlink (exists locally)
composer dev                                        # artisan serve + queue:listen + vite
```

Env vars beyond Laravel defaults: `OPENAI_API_KEY`, `OPENAI_ORGANIZATION`,
`DMS_CERTIFICATE_PRIVATE_KEY_PASSWORD` (read by `config/digital-certificate.php`; **not yet
listed in `.env.example`**). Certificate files expected in `storage/app/certificates/`.

`vite.config.ts` hard-codes `hmr.host: 192.168.4.47` (LAN dev); change locally if your IP differs.

### Check results (run 2026-10-13, check-only modes, no files modified)

| Check | Command | Result |
| --- | --- | --- |
| Tests | `./vendor/bin/pest` | ✅ 64 passed, 407 assertions (6.8 s) |
| ESLint | `npx eslint .` | ❌ 1 error: unused `props` in `resources/js/pages/ocm-approvals/Index.vue:13` |
| Prettier | `npx prettier --check resources/` | ❌ 23 files need formatting |
| Type check | `npx vue-tsc --noEmit` | ❌ TS2688: cannot find type definitions `./resources/js/types` and `vue/tsx` (tsconfig `compilerOptions.types`) |
| Pint | `./vendor/bin/pint --test` | ❌ ~60 files with style violations (pre-existing, widespread) |
| Build | `npm run build` | Not run this session (status.md reports it passing on 2026-10-09) |

⚠️ `npm run lint` = `eslint . --fix` and `npm run format` = `prettier --write`; both modify files.
CI (`.github/workflows/lint.yml`) runs Pint/format/lint in **fix** mode, so it does not fail
on style.

## 5. Current status

Branch `main`. The referral-slip AI pre-fill fix is committed locally but **not pushed** (push when ready).
Everything before it is pushed (`2cb71ea` bell + delinquency scope + holders, `eb09eda` multi-office custody +
Released To, `75f4c3b` two-step referral). Migrations to run on other environments:
`2026_10_13_000000_add_signature_mode_to_document_trails_table`,
`2026_10_14_000000_add_released_to_offices_to_document_trails_table` (includes a data backfill). The latest fix needs
no migration.
Checks before the latest commit: Pest 71 passed (708 assertions), ESLint on changed Vue files OK, `npm run build` OK,
Pint on changed test/request OK.

### Implementation status
- Completed (automated tests pass, 71/71): intake/registration, dispatch, processing, two-step ADM
  referral (generate → Department Head E-/Physical signature → Records), OCM custody + approval,
  stale-form protection, rollback on failure, dashboard metrics.
- Unverified in a browser: notification bell + Receive modal, role-scoped delinquency card, multi-office
  inbox behaviour, OCM PDF signing in a PDF reader, stale-form UX in two tabs. (User walked the two-step
  referral with E-Signature through to dispatch on 2026-10-14.)
- `DatabaseSeeder` now creates one Department Head (`<code>.dh@gmail.com`) and one Staff (`<code>.staff@gmail.com`)
  per office, password `password`. Run `php artisan db:seed` locally to get them (not run yet). Note: re-seeding also
  resets the four executive/admin accounts' passwords to `password` and their office (existing seeder behaviour).

## 6. Known issues, risks & technical debt

**Security (high)**
1. ~~`public/certificates/` contained `private.key` and a `.p12` bundle (web-accessible, not ignored).~~
   **Resolved 2026-10-13:** user deleted the folder (verified absent; copies remain in
   `storage/app/certificates/`). Still recommended: add `/public/certificates` to `.gitignore`.
2. `.gitignore` contains unresolved merge-conflict markers (`<<<<<<< HEAD … >>>>>>>`). Git treats
   the marker lines as patterns; rules still mostly apply, but the file should be cleaned up.
3. Document attachments are stored on the `public` disk (`storage/app/public/documents`) and are
   reachable by URL without authorization.
4. `Document::scopeVisibleTo()` only filters `state = 1`; every active user can list/view every
   active document. Confirm this is intended.
5. `HandleInertiaRequests` shares the full `User` model to the browser (password/2FA fields are
   hidden, but `signature_path` etc. are exposed).

**Maintainability**
6. `DocumentController::create()` renders a non-existent `documents/Create` page and has no route (dead code).
7. `app/Models/User.php` imports `Laravel\Fortify\TwoFactorAuthenticatable` but Fortify is not installed (unused import).
8. `routes/web.php` declares `dashboard` twice (closure and `DashboardController`); the later one wins.
9. `findStampPlacement` calls OpenAI directly from the controller instead of a service; model name
   `gpt-4o-mini` hard-coded in two places. (The duplicated `ReferralSlipController::index` query was fixed 2026-10-13.)
10. Migrations add → remove → re-add `signed_file_path` on `document_trails` (2026_10_01 ×2, 2026_10_06).
11. Print template hard-codes the City Administrator's name (per `status.md`, intentional).
12. `vue-tsc` broken via tsconfig `types`; Pint/Prettier debt across most files; no `typecheck` npm script.
13. `README.md` still begins with the Laravel starter-kit text; `composer.json` name is `laravel/vue-starter-kit`.
14. Vite warns about a >500 kB chunk (OCR/PDF libs) per `status.md`.
15. **Mixed timestamp conventions:** app timezone is UTC, so `created_at`/`updated_at` are real UTC while
   workflow timestamps are Manila wall-clock labelled as UTC. Frontends must treat them differently (see Show.vue
   `formatUtcDateStr`). Changing `APP` timezone would require migrating existing rows and re-checking SLA math
   (`Document::getIsDelinquentAttribute`, DashboardController) — needs a decision before touching.
16. `.env.example` defaults to SQLite while local dev uses MySQL; `DMS_CERTIFICATE_PRIVATE_KEY_PASSWORD` missing.
17. SLA/delinquency (`Document::is_delinquent`, dashboard) evaluates only `department_id`; with multi-office copies
   it reflects one holder. Per-copy SLA would need a per-trail calculation.

## 7. Next steps (suggested, none started)

1. Browser-test the bell (dispatch to an office with a user, click the ping, Receive & End / Route) and the
   role-scoped delinquency card; revert specific commits if behaviour is not as intended.
   Also register a new ADM document with AI analysis and confirm Generate Slip pre-ticks the suggested offices
   (local doc 1 has only an ADM suggestion, so it correctly pre-fills nothing). Then push the local commit.
2. Add `/public/certificates` to `.gitignore` and resolve the `.gitignore` conflict markers (needs approval).
3. Fix the ESLint error in `ocm-approvals/Index.vue` and the tsconfig `types` entry so `vue-tsc` runs.
4. Add `DMS_CERTIFICATE_PRIVATE_KEY_PASSWORD=` to `.env.example`.
5. Decide on authorization for attachment downloads and for `visibleTo` scoping (security #3, #4).
6. Decide on the timestamp convention (#15) and per-copy SLA (#17) before further date/SLA work.
7. Transactions page never receives `flash` from `TransactionController` (success toast after processing does not show).
8. Remove dead code (#6–#8, and the unused `DocumentAnalysisService`, whose prompt duplicates the one in
   `DocumentController::analyze`) in a small cleanup change with tests.

## 8. Decisions & assumptions

- Decision (existing code): optimistic concurrency via `workflow_version` + pessimistic row locks inside `mutate()`.
- Decision (existing code): executive approvals keyed by department code, not IDs.
- Assumption: `status.md` is user-maintained and authoritative for feature intent; this file
  documents architecture and handoff state and does not duplicate its feature checklist.
- Assumption: local environment is XAMPP on Windows (path `C:\xampp\htdocs\dms`).

## 9. Change log

### 2026-10-14 — Government-style sign-in page (Claude Code)
- `AuthLayout.vue` rewritten (used by every auth page): identity bar (Republic of the Philippines / City Government),
  navy identity panel with the city seal + DMS chevron mark, form panel, footer. Palette from the DMS logo added to
  `tailwind.config.js` as `gov.{navy,navy-dark,blue,orange,gold}`; new `components/DmsLogoMark.vue` (SVG redraw of
  the logo; swap for the original file if available).
- `Login.vue`: Sign-up link removed (accounts are created by the System Administrator), show/hide password,
  positive tabindex values removed, errors tied to inputs via `aria-describedby`.
- `Login.vue` has Employee / Guest tabs (radix-vue Tabs). Guest tab (Barcode number + Access code) is a
  **placeholder**: button disabled, no route, nothing submitted; a public lookup endpoint (rate-limited, matching
  `barcode` + `access_code`) is still to be designed.
- **Open (security):** `/register` route and `Register.vue` still exist, so anyone can still self-register by URL.
- Checks: ESLint ✅; build ✅; Auth tests 16 passed. Not viewed in a browser.

### 2026-10-14 — Intake AI autofill: visible failure reasons; PNG uploads fixed (Claude Code)
- **User report:** AI autofill fails from `documents/Index.vue`. Server path verified working (direct `analyze()` run
  with sample OCR text returned 200 with all fields; OpenAI key OK). Exact browser-side cause not yet identified.
- `DocumentController::analyze` now logs the swallowed exception (`Document intake analysis failed.`) — check
  `storage/logs/laravel.log` after the next failure.
- `documents/Index.vue`: images (PNG/large JPG) are re-encoded to an 800px JPEG thumbnail (PNG was sent as-is and
  always failed `ai_thumbnail` `mimes:jpg,jpeg`); empty OCR text stops early with a message; the failure reason
  (422 field error, 419 session, 403, server error, browser read error, non-JSON redirect) is shown above the form.
- Observed, not fixed: in the sample run the model returned barcode `C000000003` for OCR text `C003196699`.
- Checks: ESLint ✅; build ✅; DocumentAnalysisTest 8 passed.

### 2026-10-14 — OCM approval: success toast survives; stamp stays on the page (Claude Code)
- **Bugs (user-reported):** after the Mayor's PNPKI stamp the page did `window.location.reload()` right after the
  toast, so the toast vanished; the stamp's certificate name ran past the page edge and the AI rectangle was not
  checked against the page.
- `ocm-approvals/Index.vue`: `router.reload({ only: ['documents'] })` instead of a full reload (toast stays); the AI
  rectangle is used only if it fits on the page and the rendered pixels there are blank, otherwise the measured
  pixel scan picks the area (now 300 wide); page pixels read once per page. Unused `props` removed (fixes the
  ESLint error noted in §4).
- `ApproveOcmDocumentRequest`: shared `placementRules()` (min width 250) + `ensureFitsOnPage()` (x+width and
  y+height ≤ 1000), reused by `ReferralSlipController::findStampPlacement`; prompt states the bounds.
- `PdfApprovalStampService`: text scales down (to 60% min) to fit the stamp box; date and file name use Asia/Manila
  (the stamp showed UTC, e.g. 00:41 instead of 08:41).
- New `tests/Feature/OcmStampPlacementTest.php`. Pest 74 passed (816 assertions); Pint ✅; ESLint ✅; build ✅.
  Not verified with a real signed PDF in a browser.

### 2026-10-14 — Seeder: one Department Head + one Staff per office (Claude Code)
- `DatabaseSeeder`: generates `<code>.dh@gmail.com` (department_head) and `<code>.staff@gmail.com` (staff) for all
  48 offices (96 accounts, password `password`). Executive/admin accounts now resolve their office by code instead of
  IDs 24/43/3/31. The old `dh@gmail.com` / `staff@gmail.com` entries were removed from the seeder (the local rows are
  untouched; locally they sit in ADM and CHO, so ADM/CHO will have two heads/staff until those are deactivated).
- Bug fixed: `document_types` used `upsert()` keyed on `document_code`, which has no unique index, so every MySQL
  re-seed inserted 12 duplicates (and SQLite refused it). Now `updateOrInsert` like the other registries.
- New `tests/Feature/DatabaseSeederTest.php` (seeds twice; one head + one staff per office; no duplicate types).
  Pest 72 passed (810 assertions); Pint applied to the seeder. Seeder not yet run against local MySQL.

### 2026-10-14 — Referral slip: AI routing suggestions pre-fill again (Claude Code)
- **Bug (user-reported):** Generate Referral Slip did not pre-select the AI-suggested departments. Cause: the AI put
  ADM itself (the referral office) in `routing_suggestions` (local doc 1 stored only `[{department_id:"3"}]`). ADM is
  excluded from the slip's department list, so nothing visible was ticked, while id 3 was pre-ticked invisibly and
  would fail `targets.*` notIn validation on submit.
- `referral-slips/Index.vue`: pre-ticks only suggestions for listed offices; hint line says whether AI suggestions were
  applied; suggestion reason on hover.
- `DocumentController::analyze`: prompt uses ADM/OCM ids resolved by code (was hard-coded 3/31) and forbids ADM in
  routing suggestions; new `routingSuggestions()` validates AI output (active offices only, excludes ADM and the
  executive target, int ids, priority Primary/Secondary/FYI, dedupe, max 4).
- `SaveDocumentRequest`: nested rules for `ai_routing_suggestions.*`.
- Existing documents whose only suggestion was ADM (e.g. local doc 1) still have nothing to pre-fill; the modal says so.
- Checks: Pest 71 passed (708 assertions; new `DocumentAnalysisTest` case); ESLint ✅; Pint on changed test/request ✅;
  build ✅. Not verified in a browser.

### 2026-10-14 — Role-scoped delinquency + incoming-document notification bell (Claude Code)
- **Dashboard:** delinquent card/report (and its To-Do / Due Tomorrow counts) cover all departments only for
  `system_administrator`, `city_administrator`, `records_manager` (`DashboardController::ALL_DEPARTMENT_ROLES`);
  everyone else sees documents their office holds (`Document::heldBy`). Payload adds `delinquent.scope` (all|office);
  card shows "All departments" / "Your office"; report title says "(Your Office)" when scoped. Monthly cards,
  pipeline and processing times are unchanged (still global).
- **Bell (AppSidebarLayout):** new `IncomingDocumentService` — "incoming" = open trail entry at the user's office
  with `received_by` null (nobody there has acted yet; `release()` sets it). No new table. Executive-stage pings go
  only to the acting role (slip -> city_administrator, signature -> ADM department_head, OCM -> city_mayor).
  Shared lazily as `incoming` {count, items[5]} by `HandleInertiaRequests`; `usePoll(60000, {only:['incoming']})`
  refreshes it (async, paused when tab hidden). Items link to: Office Inbox `?document=ID`, Referral Slips, OCM
  Approvals, or the documents list (signed slip back in Records).
- **Office Inbox:** `focusDocument` prop auto-opens the modal; actions relabelled "Receive & Route" /
  "Receive & End Transaction" (receiving is recorded with the action). Fixed missing `Sparkles` import.
- **Security/flow fix:** `DocumentPolicy::process` refuses documents in an executive stage
  (`Document::EXECUTIVE_STAGES`) and the inbox excludes them — previously an ADM user could route a document
  awaiting its referral slip from the Office Inbox, skipping the slip.
- **Tests:** new `tests/Feature/IncomingDocumentsTest.php` (ping lifecycle, focus doc, executive routing/permission);
  `DashboardTest` scoped vs all-departments. Pest 70 passed (706 assertions); ESLint ✅; build ✅; Pint on new PHP ✅.
  Local check: City Mayor (OCM) has 1 ping (document 1). Not verified in a browser.

### 2026-10-14 — `Document::holders` relation; documents list shows every holding office (Claude Code)
- **User concern:** a 3-office dispatch leaves `documents.department_id` = one office (CHO). A single integer column
  cannot hold several offices; the open trail entries are the normalized holder set.
- Added `Document::holders()` (belongsToMany Department through `document_trails` where `released_at` is null,
  distinct). Verified on local document 1: `holders` = CHO, ENR, OCM while `department_id` = 10 (CHO).
- `DocumentController::index` eager-loads `holders`; `show` uses the relation. `documents/Index.vue` label now reads
  "With CHO, ENR, OCM" (full names on hover) and the route/edit gating uses "does my office hold a copy".
- `department_id` semantics (documented on the relation): primary office only — first dispatch target, or the
  latest office a copy moved to.
- Checks: Pest 67 passed (570 assertions; multi-office test asserts holders + index payload); ESLint ✅; build ✅.

### 2026-10-14 — Trail "Released To" shows every office of a multi-office dispatch (Claude Code)
- **Why:** `document_trails.released_to` holds one office, so a 3-office dispatch showed only the first in Show.vue.
- Migration `2026_10_14_000000_add_released_to_offices_to_document_trails_table`: JSON `released_to_offices`
  (+ backfill of past dispatches from "Dispatched from Records" arrivals within ±5 s; verified locally: trail #4 ->
  [10, 20, 31]). `released_to` still stores the first office for existing consumers.
- `DispatchService` records all targets; `DocumentTrail` casts the column; `DocumentController::show` adds
  `released_to_list` per trail (falls back to `releasedToOffice` for single-office releases); Show.vue renders one
  badge per office.
- Checks: Pest 67 passed (558 assertions, multi-office test extended); Pint on new/changed PHP ✅; ESLint ✅; build ✅.
  Not verified in a browser.

### 2026-10-13 — Multi-office dispatch: every office gets an actionable copy (Claude Code)
- **Bug (user-reported):** dispatching to 3 offices wrote 3 trail entries but `documents.department_id` held only
  the first, so offices 2 and 3 never saw it in their inbox and were refused by the `process` policy.
- **Rule (user decision):** each office ends or routes its own copy independently; the document is Completed only
  when no office holds an open copy.
- **Model:** custody = open trail entries (`released_at IS NULL`). `department_id` remains a single "latest/primary"
  office for legacy consumers.
- **Changes:** `DocumentWorkflow::openOffices()`; `release()` takes an optional `$office`; `mutate()` accepts a null
  version (per-office actions skip the document-wide stale check and guard with the office's open copy instead).
  `TransactionService::process` rewritten (end closes own copy, completes when none remain; route refuses an office
  already holding a copy). `DocumentPolicy::owns` uses open trail entries (falls back to `department_id` if none).
  `Document::scopeHeldBy`; Transactions inbox uses it. `ProcessDocumentRequest` excludes the user's own office as a
  target. `DocumentService::deactivate` closes every open copy. `DocumentController::show` passes `holders`;
  Show.vue lists all holders in "Currently with" and marks each holding office in the route strip (also fixed
  `slipTargets.map(officeOf)` passing the index as a flag).
- **Existing data:** documents already dispatched to several offices need no migration — offices 2..n already have
  open trail entries, so the document now appears in their inboxes.
- **Tests:** 3 fixtures updated to move the open trail with `department_id`; 2 new tests (independent end/route,
  no duplicate holder, completion on last copy; deactivation closes all copies). Pest 67 passed (547 assertions);
  Pint on `TransactionService.php` ✅; ESLint Show.vue ✅; build ✅. Not verified in a browser.
- **Not changed:** `Document::is_delinquent` and the dashboard SLA still evaluate only `department_id`, so a
  multi-office document's delinquency reflects one office (noted as known issue #17).

### 2026-10-13 — Show.vue: latest-first trail table; Date Start timezone fix (Claude Code)
- Routing Trail table lists newest entry first (transit duration still computed chronologically).
  The Document Route strip was briefly reversed and then **reverted at the user's request**: it stays oldest -> newest.
- Bug fixed: Date Start showed UTC (8 hours behind). Cause: `config/app.php` timezone is `UTC`, so `created_at`
  is stored/serialized as true UTC, while workflow timestamps (`received_at`, `released_at`, `due_date`,
  `completed_at`) are written with `now('Asia/Manila')` and stored as Manila wall-clock. Show.vue strips the
  "Z" for wall-clock fields; `created_at` now uses `formatUtcDateStr` (converts to Asia/Manila).
  Verified: stored `2026-10-13T03:27:48Z` renders "Oct 13, 2026 11:27 AM" (matches its trail received 11:27).
- Checks: `npx eslint` ✅; `npm run build` ✅.

### 2026-10-13 — Two-step City Administrator referral + Document Route view (Claude Code)
- **Why:** user wants the City Administrator to only generate the slip, then the ADM Department Head
  (Atty.) chooses E-Signature or Physical Signature before the document returns to Records; and the
  document page must show office-to-office movement plainly (no "System" placeholder).
- **Decisions (from user):** generator = `city_administrator` account; signer = `department_head` user
  whose department code is `ADM`; Physical Signature = slip prints with a blank line for a wet signature.
- **Flow now:** intake to ADM moves custody REC -> ADM ("Awaiting referral slip") ->
  City Administrator generates slip (ADM -> ADM, `requires_signature_from = department_head`) ->
  ADM Department Head signs (`signature_mode` electronic|physical; ADM -> REC) -> Records dispatches.
  Reuses the existing `department_head` enum value on `documents.requires_signature_from`.
- **Backend:** new `SignReferralRequest`, `ReferralService::sign()`, `ReferralSlipController::sign`,
  route `referral-slips.sign`; policy `signReferral` + ADM-head access to `viewReferrals`; `refer` limited
  to the `city_administrator` role and blocked once a slip awaits signature; `dispatch` blocked while
  `department_head` is pending. `generate()` no longer signs or stamps (dead OCM stamp branch removed;
  OCM uses its own queue). Slip print/preview take the signature from the signing trail (electronic only);
  legacy one-step slips still render. Migration `2026_10_13_000000_add_signature_mode_to_document_trails_table`
  (applied locally). `HandleInertiaRequests` shares `auth.canViewReferrals` for the sidebar.
  `DocumentController::show` passes `slipTargets`.
- **Frontend:** `referral-slips/Index.vue` rewritten (stage column; Generate modal vs Review & Sign modal with
  slip preview + E-/Physical choice; OCM/pdf.js code removed). `documents/Show.vue`: Document Route strip
  (stops, Current, Next, Completed), "System" fallback removed, Physical Signature badge.
  `documents/Index.vue`: pending labels incl. "Pending Dept. Head Signature"; slip button hidden until signed.
  `AppSidebar.vue`: Referral Slips visibility from `canViewReferrals`.
- **Tests:** `WorkflowReliabilityTest` updated to the new flow + new test for Dept Head signing rules.
  `./vendor/bin/pest` -> 65 passed (456 assertions). ESLint (changed files) ✅, `npm run build` ✅,
  Pint on `ReferralService.php` ✅. Not verified in a browser.
- **Required before use:** create an active `department_head` user in the ADM department and upload their
  signature (needed for E-Signature). Documents registered earlier with ADM review still in Records custody
  are handled: generating their slip moves them REC -> ADM.

### 2026-10-13 — Show.vue: no placeholder dashes (Claude Code)
- Modified `resources/js/pages/documents/Show.vue`. User request: empty data should not render `-`/`N/A`.
- Date/duration helpers now return an empty string instead of `-`. Detail rows (Access Code, Date End,
  Transaction/Document Type, Delivery Method) are hidden when empty; Linked/Attachments sub-sections
  hidden when empty (panel shows one muted "No linked documents or attachments." line when both are
  empty); "Currently with" line hidden without a department; trail cells left blank instead of `-`.
  Receiver still falls back to "System" (existing behaviour).
- Checks: `npx eslint` on the file ✅; `npm run build` ✅. Not verified in a browser.

### 2026-10-13 — Document detail page follows legacy DTS layout (Claude Code)
- Modified `resources/js/pages/documents/Show.vue` (template, icon imports, `linkedBarcodeList` computed
  replaces `linkedBarcodes`; no backend change).
- Why: user wants the DMS to mirror the legacy DTS document view (screenshot provided) to reduce
  confusion for staff, but look visibly improved rather than copied.
- Layout now matches DTS: three side-by-side panels (Document Details label/value list | Source &
  Subject Matter | Linked Documents & Attachments) and the 11-column trail table grouped as
  RECEIVED (Office, By, Date/Time, Action) | RELEASED (By, Date/Time, Action, Released To) |
  DURATION (Office, Transit) | REMARKS.
- Improvements over DTS: DMS card styling with icon header bars; page header with barcode + status/
  Delayed/Delinquent badges and current office; office codes as badges (full name on hover);
  linked barcodes as chips; attachment buttons; Signed/Returned/Pending badges; required-actions box.
  Table uses `table-fixed` widths + wrapping (`min-w-[60rem]`) so it fits desktop widths without
  sideways scrolling.
- Not carried over from DTS: the print icon (no document print view exists yet).
- Checks: `npx eslint` on the file ✅; `npm run build` ✅. Not verified in a browser.

### 2026-10-13 — Document detail page restyled to match Index (Claude Code)
- Modified `resources/js/pages/documents/Show.vue` (template + icon imports; no logic/backend change).
- Why: user felt the page looked too plain next to `documents/Index.vue`; asked to adopt its design
  while keeping the structure (action bar -> details card -> 6-column trail table).
- Changes: Index-style page header (text-2xl title with emerald mono barcode, status/Delayed/
  Delinquent badges from existing `status`, `is_delayed`, `is_delinquent`, current office line);
  rounded-xl pill buttons (Back, View Scanned Document, OCM PDF); cards with `bg-slate-50` header
  bars + lucide icons; Index table header style (`bg-slate-100`, uppercase, `px-4 py-2.5`); numbered
  emerald step badges; Index badge styles for Signed/Returned/Pending; subject matter in a tinted box.
- Checks: `npx eslint` on the file ✅; `npm run build` ✅. Not verified in a browser.

### 2026-10-13 — Documents index: form height tweak (Claude Code)
- Modified `resources/js/pages/documents/Index.vue` (template only).
- A broader compaction (tighter padding/inputs/table, side-by-side fields) was tried and **reverted
  at the user's request** ("way too compact"); the original spacing and layout are restored.
- Kept change: Document Classification moved into the Source Type / Source Location row
  (2 → 3 columns), removing one full form row so the page needs less vertical scroll.
  Also kept `aria-label="Search documents"` on the search input.
- Checks: `npx eslint` on the file ✅; `npm run build` ✅. Not verified in a browser.
- Known (pre-existing, unchanged): form `<label>`s not associated with inputs; commented-out
  AI debug-metrics block in the template.

### 2026-10-13 — Compact document detail page (Claude Code)
- Modified `resources/js/pages/documents/Show.vue` (template + one computed; no backend change).
- Why: the 11-column routing-trail table (`min-w-max`) forced horizontal scrolling; user wants
  all data visible on open.
- Changes: trail table regrouped into 6 fixed-width, wrapping columns (#, Received, Released,
  Released To, Duration, Remarks); details card compacted into a semantic `<dl>` grid (2/4/6 cols);
  attachment links moved to the action bar as buttons. Horizontal scroll now only below ~48rem.
- Bug fixes in passing: "None" showed next to "View Scanned Document" whenever no OCM PDF existed
  (misplaced `v-else`); `linked_documents` (array cast) rendered as raw JSON — now comma-joined.
- Accessibility: `<dl>`/`<dt>`/`<dd>`, `scope="col"`, labelled sections, `aria-hidden` icons,
  `rel="noopener"` on new-tab links.
- Checks: `npx eslint resources/js/pages/documents/Show.vue` ✅; `npm run build` ✅ (5.3 s).
  Not verified in a browser.
- `public/certificates/` deleted by the user (verified absent).

### 2026-10-13 — Documentation setup (Claude Code)
- Created `CLAUDE.md`, `documentation.md`, `.claude/skills/senior-dev-workflow/SKILL.md`.
- Why: establish persistent project rules, handoff state, and a repeatable dev workflow.
- No application code, config, or existing docs were modified. Nothing committed.
- Checks run: see §4 table.

### Earlier history (from `git log`, summarized)
- 2026-10-05 `7869ef7` adjusted document trails + embedded digital signature
- 2026-09-25 `baadf7b` changed referral slip features, added printable files
- 2026-09-23 `f23c78f` added referral slip, restructured the database, added signature
- 2026-09-22 `fb505bd` / `09bb098` digital signature on users; role changes
- 2026-09-18 `18681f8` / `99e79e9` dashboard connected to documents; input validation; AI prompt debugging
- Older: initial commit, AI-integrated registries, audit logs, UI changes (see `git log`).
