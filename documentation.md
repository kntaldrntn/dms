# DMS — Project Documentation & Development Handoff

> Persistent handoff file. Read before continuing work; update at milestones, before
> handoff, and before `/compact`. Mark facts as **verified** only with evidence.
> Feature-level health is tracked separately by the user in `status.md`.

Last updated: 2026-10-13 (state refreshed after two-step ADM referral, Document Route, timezone fix)

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
| `app/Services/DispatchService.php`, `TransactionService.php` | Records dispatch and department inbox processing, both via `mutate()`. |
| `app/Services/ReferralService.php` | Two-step ADM referral: `generate()` (City Administrator, slip only → `requires_signature_from=department_head`) and `sign()` (ADM Department Head, `signature_mode` electronic/physical → back to REC). OCM approval `approveOcm()` uses `PdfApprovalStampService`. |
| `app/Services/PdfApprovalStampService.php` | Stamps the uploaded PDF with a visible signature block and signs it with the certificate from `config/digital-certificate.php`; writes to `storage/app/private/signed-documents` (local disk). Original upload untouched. |
| `app/Services/DocumentAnalysisService.php` | OCR text + thumbnail → OpenAI JSON extraction for intake (prompt reference: `basis.md`). |
| `app/Policies/DocumentPolicy.php` | All document abilities: `viewAny`, `view`, `create`, `update`, `delete`, `dispatch`, `process`, `viewReferrals`, `viewOcmApprovals`, `refer`, `signReferral`, `viewReferralSlip`. Ownership = user's department equals document's current department. |
| `app/Http/Requests/*` | `SaveDocumentRequest`, `DispatchDocumentRequest`, `ProcessDocumentRequest`, `GenerateReferralRequest`, `SignReferralRequest`, `ApproveOcmDocumentRequest`, `AnalyzeDocumentRequest`, `DocumentMutationRequest` (base: requires `workflow_version`). |
| `app/Http/Controllers/DashboardController.php` | Invokable; SLA/delinquency/to-do metrics and drill-down. |
| `app/Http/Middleware` | `HandleRoles` (`role:` alias), `CheckWorkingHours`, `TrackUserActivity`, `HandleInertiaRequests` (shares `auth.user`, `auth.canViewReferrals`). |
| `app/Models` | `Document` (computed `is_delayed`, `is_delinquent`), `DocumentTrail`, `Department`, `User`, registries (`DocumentType`, `TransactionType`, `DocumentClassification`, `DeliveryMethod`, `PerformanceStandard`), `AuditLog`, `LoginHistory`. Active/inactive uses integer `state` (1/0). |
| `resources/views/referral-slips/print.blade.php` | Printable/PDF referral slip. |

### Roles (string column `users.role`)
`system_administrator`, `records_manager`, `city_administrator`, `city_mayor`,
`department_head`, `staff`.

### Department codes the code depends on
`REC` (Records), `ADM` (City Administrator), `OCM` (Office of the City Mayor). Exactly one
active department per code is required (`DocumentWorkflow::office()`).

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

Branch `main`. Last commit `e6b7549` (2026-10-13, pushed to origin) "Add OCM digital approval,
dashboard metrics update, DTS-style document view, and project docs" — contains all work up to
and including the DTS-style Show.vue and the docs/skill.

### Uncommitted working tree (verified 2026-10-13)
The two-step ADM referral flow, Document Route strip, latest-first trail table, and Date Start
timezone fix (see §9 entries dated 2026-10-13 above the "Documentation setup" entry):
modified `DocumentController`, `ReferralSlipController`, `HandleInertiaRequests`,
`GenerateReferralRequest`, `DocumentTrail`, `DocumentPolicy`, `DocumentService`, `ReferralService`,
`AppSidebar.vue`, `documents/Index.vue`, `documents/Show.vue`, `referral-slips/Index.vue`, `ziggy.js`,
`routes/web.php`, `WorkflowReliabilityTest.php`, `documentation.md`; new `SignReferralRequest.php` and
migration `2026_10_13_000000_add_signature_mode_to_document_trails_table.php` (applied locally, batch 2).

### Implementation status
- Completed (automated tests pass, 65/65): intake/registration, dispatch, processing, two-step ADM
  referral (generate → Department Head E-/Physical signature → Records), OCM custody + approval,
  stale-form protection, rollback on failure, dashboard metrics.
- Unverified in a browser: the two-step referral UI, Document Route strip, OCM PDF signing in a PDF
  reader, stale-form UX in two tabs.
- Blocked for real use of the referral flow: no active `department_head` user exists in ADM locally;
  one must be created (with an uploaded signature for E-Signature).

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

## 7. Next steps (suggested, none started)

1. Create an ADM `department_head` user (+ signature) and manually walk the referral flow:
   Records intake → City Administrator generate → Dept Head sign (try both modes) → Records dispatch;
   check the Document Route strip and printed slip at each step.
2. Commit/push the uncommitted referral + Show.vue work once verified (needs user authorization).
3. Add `/public/certificates` to `.gitignore` and resolve the `.gitignore` conflict markers (needs approval).
4. Fix the ESLint error in `ocm-approvals/Index.vue` and the tsconfig `types` entry so `vue-tsc` runs.
5. Add `DMS_CERTIFICATE_PRIVATE_KEY_PASSWORD=` to `.env.example`.
6. Decide on authorization for attachment downloads and for `visibleTo` scoping (security #3, #4).
7. Decide on the timestamp convention (#15) before any further date/SLA work.
8. Remove dead code (#6–#8) in a small cleanup change with tests.

## 8. Decisions & assumptions

- Decision (existing code): optimistic concurrency via `workflow_version` + pessimistic row locks inside `mutate()`.
- Decision (existing code): executive approvals keyed by department code, not IDs.
- Assumption: `status.md` is user-maintained and authoritative for feature intent; this file
  documents architecture and handoff state and does not duplicate its feature checklist.
- Assumption: local environment is XAMPP on Windows (path `C:\xampp\htdocs\dms`).

## 9. Change log

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
