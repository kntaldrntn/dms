# DMS — Project Documentation & Development Handoff

> Persistent handoff file. Read before continuing work; update at milestones, before
> handoff, and before `/compact`. Mark facts as **verified** only with evidence.
> Feature-level health is tracked separately by the user in `status.md`.

Last updated: 2026-10-26 (client status email; sidebar sections). Sections 5 and 7 predate FGD Phase 2 — see the change log.

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
| AI | `openai-php/laravel` — intake extraction (`DocumentIntakeAnalyzer`, default `gpt-6-luna`) and `gpt-4o-mini` vision for approval stamp placement | `DocumentIntakeAnalyzer`, `OcmApprovalController::findStampPlacement` |
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
| `app/Services/AdmApprovalService.php`, `OcmApprovalService.php` | FGD executive approvals: ADM receive → City Administrator single approval → OCM or departments; OCM approval (returns to Records until Phase 3). Both stamp via `PdfApprovalStampService` with the signer's own PNPKI certificate (`SigningCertificateService`). Referral slips were retired in Phase 2d. |
| `app/Services/PdfApprovalStampService.php` | Stamps the uploaded PDF with a visible signature block and signs it with the certificate from `config/digital-certificate.php`; writes to `storage/app/private/signed-documents` (local disk). Original upload untouched. |
| `app/Services/DocumentIntakeAnalyzer.php` | Live intake AI (called by `DocumentController::analyze()`): OCR text only (pages 1, 2, last) → strict JSON schema whose ids come from the registries + the type rulebook; answer re-checked server-side (allowed classification, default category, one Primary office, ADM/OCM excluded from routing). Model: `config('services.intake_ai.model')`. |
| `app/Services/ClientStatusService.php`, `app/Jobs/NotifyClientOfStatus.php` | The client-visible latest status, and the queued email sent when it changes (after every `mutate()` and intake). |
| `app/Services/DocumentAnalysisService.php` | **Unused** older copy of the intake prompt (prompt reference: `basis.md`); not wired to any route. |
| `app/Policies/DocumentPolicy.php` | All document abilities: `viewAny`, `view`, `create`, `update`, `delete`, `dispatch`, `process`, `viewReferrals`, `viewOcmApprovals`, `refer`, `signReferral`, `viewReferralSlip`. Ownership = user's department equals document's current department. |
| `app/Http/Requests/*` | `SaveDocumentRequest`, `DispatchDocumentRequest`, `ProcessDocumentRequest`, `ReceiveAdmDocumentRequest`, `ApproveAdmDocumentRequest`, `ApproveOcmDocumentRequest`, `AnalyzeDocumentRequest`, `Settings/SigningCertificateRequest`, `DocumentMutationRequest` (base: requires `workflow_version`). |
| `app/Http/Controllers/DashboardController.php` | Invokable; SLA/delinquency/to-do metrics and drill-down. Delinquency is all-departments for `ALL_DEPARTMENT_ROLES`, otherwise the user's held documents. |
| `app/Http/Middleware` | `HandleRoles` (`role:` alias), `CheckWorkingHours`, `TrackUserActivity`, `HandleInertiaRequests` (shares `auth.user`, `auth.canViewReferrals`, lazy `incoming`). |
| `app/Models` | `Document` (computed `is_delayed`, `is_delinquent`; `holders()` = offices with an open copy; `scopeHeldBy`; `EXECUTIVE_STAGES`), `DocumentTrail`, `Department`, `User`, registries (`DocumentType`, `TransactionType`, `DocumentClassification`, `DeliveryMethod`, `PerformanceStandard`), `AuditLog`, `LoginHistory`. Active/inactive uses integer `state` (1/0). |

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
Feature pages: `documents/{Index,Show,Edit}`, `transactions/Index`, `adm-approvals/Index`, `ocm-approvals/Index`, `settings/Certificate`,
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

### FGD process transition (started 2026-10-21; Phases 0–1 pushed at `79f08d8`; Primary/copy routing pushed at `e7e0768` on 2026-10-22; next: Phase 2)

Source: Google Slides "Document Management Focus Group Discussion" (presentationId
`1re2mVrRkyyvndW1gAm3F6DvMXTyrgjYDKDS0AzLelno`), slides 5–9 (workflows, RBAC) and 13–16 (FGD answers).
Checkpoint before the transition: commit `8f59dea` (Pest 74 passed, 816 assertions).

**User decisions (2026-10-21):** City Administrator gives a single approval (no slip generate + ADM Dept Head
signature); paperless vs paper route comes from the Document Type (Records may override); start with fresh
document data (keep offices, users, registries); no migration of in-flight documents.

**FGD decisions already in the deck:** 24 h delinquency per office for now (CC timeline review later, by CART);
delayed = end-to-end beyond the category limit (Simple 3 / Complex 7 / Highly Technical 20 days, RA 11032);
ADM monitors and tags delinquency; confidential documents are tagged at intake, get no AI assist and no upload;
ADM/OCM route directly to the concerned departments (not back to Records); supervisory level can change the
category; external clients see the latest status only.

**Keep as-is:** `DocumentWorkflow` (mutate/locks/`workflow_version`/release/receive), multi-office copies and
`holders`, trail table, notification bell, intake AI + barcode/OCR, `PdfApprovalStampService`, registries.

**Phases** (each a separate, tested change):
0. ✅ **Done 2026-10-21** — Document Type rulebook (`signature_type`, `may_be_confidential`,
   `default_transaction_type_id`, `rulebook_remarks`) + classification-per-type pivot, seeded from the sheet and
   editable in the registry; 1440-min delinquency default; registries opened to Records (users stay Sys Admin).
   The "category" already exists per document as `transaction_type_id` (Simple 3 / Complex 7 / HT 20 days), so no
   new category column. Document fields and the stage refactor moved to Phases 1–2, where they are first used.
1. ✅ **Done 2026-10-21** — document `is_confidential` + `route_mode`; rulebook enforced server-side and mirrored
   on the intake/edit forms (see change log). Intake routing (ADM/OCM/direct) and the fresh-data reset were left for
   Phase 2, because Phase 2 rewrites those flows and the old documents still work under Phase 1.
2. **Plan (2026-10-23, awaiting user approval of the defaults below), one tested commit per step:**
   - 2a. Explicit stage: migration adds `document_trails.stage` (null = normal processing, `adm_approval`,
     `ocm_approval`) on the open Primary entry; queues, policy, inbox and bell read it instead of
     `documents.requires_signature_from` (kept read-only until 2d, then dropped in a new migration).
   - 2b. Intake "Direct to": City Administrator, City Mayor, or Departments (Primary + copies picker reused from
     `RoutingModal`, AI suggestions pre-ticked). ADM/OCM targets open the matching approval stage.
   - 2c. ADM single approval: ADM staff receive (bell) → City Administrator **Approve & Route**: paperless =
     PNPKI stamp via `PdfApprovalStampService`, paper = upload of the wet-signed scan → routes to OCM (stage
     `ocm_approval`) or to departments (Primary + copies, AI suggestion pre-filled). Never back to Records.
     **2c detail (2026-10-23, proposed):** new "ADM Approvals" page (sidebar; ADM users + Sys Admin) listing
     `adm_approval` documents. (1) Any active ADM user clicks **Receive** → `received_by/at` on the ADM entry;
     bell pings ADM users until received, then pings the City Administrator. (2) City Administrator **Approve &
     Route** (only after receipt): target = OCM (opens `ocm_approval`) **or** departments (OfficePicker, AI
     pre-ticked, Primary + copies); remarks optional. Paper route: upload of the wet-signed scan (PDF/JPG/PNG,
     `local` disk `signed-documents/`, served by the existing authorized `previewSigned`). Paperless: PDF stamped
     by `PdfApprovalStampService` (location/reason per office) with the placement logic moved from
     `ocm-approvals/Index.vue` into `resources/js/lib/stampPlacement.ts`; `findStampPlacement` accepts ADM too.
     Trail: release "Approved by City Administrator" (approval trail, `signed_file_path`), receive at targets.
     Referral Slips page lists only legacy `adm_signature` documents until 2d. Still signs with the configured
     certificate; **2c-2 (new step): per-signer PNPKI `.p12` + PIN** for ADM and OCM.
   - 2d. Retire referral slips: `ReferralService::generate/sign`, `department_head` stage, Referral Slips page,
     print/preview routes and template, `approved_routing_slip` use (old data is reset in 2e).
   - 2e. Reset command `php artisan dms:reset-documents` (documents, trails, uploaded/signed files; keeps offices,
     users, registries, people). Written and tested, **run only after the user confirms**. Then drop the unused
     `documents.approved_routing_slip` column in a new migration.
   - PNPKI per signer (default to confirm): each signer uploads their own `.p12` once to
     `storage/app/certificates/users/{user_id}.p12` (local disk, never public); the PIN is typed at each
     signing and never stored. Replaces the single Mayor certificate in `config/digital-certificate.php`.
   Original scope note: Reset document data (fresh start, user-approved 2026-10-21; confirm before running). Intake target choice:
   ADM, OCM or departments directly. Replace the overloaded `requires_signature_from` with an explicit stage per
   open copy. ADM: ADM staff
   receive (notified) → City Administrator approves (paperless: PNPKI stamp via
   `PdfApprovalStampService`; paper: scan in signed copy) → routes to OCM or departments, AI suggestion pre-filled.
   Retire referral-slip generate/sign.
3. OCM: Mayor approves (exists) → routes directly to departments.
4. Receiving office: Receiving Officer receives → Dept Head action if needed (PNPKI or wet sign + upload) →
   final action + remarks → end or route to the next office.
5. Monitoring: 24 h delinquency per open copy (fixes known issue #17); delayed by category; ADM views all.
6. Later, pending FGD answers: client status lookup, email/SMS alerts, PDF/spreadsheet reports, office-scoped
   visibility (security #4), classification-per-type filtering.

**Answers (user, 2026-10-21):**
- **Delinquency:** 1440 minutes (24 h, counted per minute) per office for now; default replaces the current
  7200-minute fallback in `Document::is_delinquent`. Per-office/type calibration stays in the `performance_standards`
  registry, pending CART's review of the Citizen's Charter.
- **RBAC:** follow deck slide 9 (the version in the memo). Matrix read from the slide image:
  | Capability | Sys Admin | Records Section | Supervisory | Workstation | External client |
  | --- | --- | --- | --- | --- | --- |
  | User management | ✔ | | | | |
  | System settings & registries | ✔ | ✔ | | | |
  | Document registration (barcode, scan, AI form) | ✔ | ✔ | | | |
  | Dashboard | all offices | all offices | all offices | own department | |
  | View transactions | all | all | all | own department | |
  | Transact | any document | any document | edit only if endorsed to them | own department | |
  | Referral slips & OCM approvals | ✔ | ✔ | sign / approve when endorsed | | |
  | Track document status | | | | | ✔ (latest status only) |
  Assumed mapping (to confirm): Supervisory = `city_administrator`, `city_mayor`, `department_head`;
  Workstation = `staff`; Records Section = users in the `REC` office (incl. `records_manager`); External client = no
  account (barcode + access code lookup). Supervisory also may adjust the category (FGD slide 14).
- **Document-type rulebook:** Google Sheet "Document Management System Sheets"
  (`1monsI2DhgNCQoWd9Kuam-KBMM5Es3XJAyfjEuFJ2OfQ`), tab *Rulebook* (ADM response) — Route = Paperless only when
  Signature = Digital **and** Confidential = No, else Paper. Current answers: Digital = Communications, Project
  Proposal (both marked Confidential = Yes, so both still Paper); Handwritten = the other 10 except Permits
  (blank, "to be consulted"). Category per type: Simple = AOC, AIR/RIS, BAC Resolution, OBR, Permits, PO, PR;
  Complex = Payroll; Highly Technical = IPW ("with extension"); varies by content = Communications,
  Disbursement Voucher (Simple for Petty Cash/AICS, Complex for procurement), Project Proposal (Complex or HT).
  Confidential is blank for OBR, Payroll, PO, PR, Permits. Tab *Classifications* maps the 18 classifications to
  types (Communications carries 17; Financial applies to 11 types; Special Mayor's Permit → Permits only).
- **PNPKI signatories:** per slide 7 (and slide 3): the Department Head signs digitally with PNPKI unless the
  document needs a wet signature; slide 3 also lists the City Administrator and the City Mayor.

- **Client tracking (FGD slide 13, 2026-10-22):** clients see only the latest status of their document, never the
  full trail. For confidential documents clients likewise see only the latest status, and only Records has access to
  the document's data. (Open: whether "only Records" also limits what receiving offices can see inside the DMS.)
- **Performance tracking (slide 13):** city-level records and reports for the Supervisory Level, Records and the
  System Administrator only; every other department sees and reports on its own department's data only.

**Built 2026-10-22 (committed and pushed as `e7e0768`; migration applied locally, verified 2026-10-22 with
`migrate:status`): Primary office + view-only copies.** User accepted all eight defaults: Records picks the Primary (AI Primary
pre-selected); Records or the Supervisory Level can change it (old Primary keeps a copy, logged as new trail entries);
copy offices only receive (name + time) and view; copies close when the Primary ends the transaction; routing on makes
the target Primary (with optional copies); delinquency counts the Primary only; confidential documents get no copies;
the client status lookup (not built yet) shows the Primary only. See the change log entry of 2026-10-22.
Original plan: When a document goes to
several offices, only the **Primary** office can act on it (receive, act, end, route on); the others get a
**view-only copy** they are notified of and can receive and view. This replaces the 2026-10-13 rule that every office
gets its own actionable copy. Added to the FGD summary doc (Records Section + Open Items).
Draft design (pending the user's answers, see the chat of 2026-10-22):
- Schema: `document_trails.copy_role` enum('primary','copy') default 'primary' (new migration). `documents.department_id`
  stays the Primary office, so existing consumers keep working.
- Dispatch (`DispatchService`, `DispatchDocumentRequest`, `RoutingModal.vue`): new `primary_office_id`, which must be one
  of `routed_to`; pre-selected from the AI suggestion marked Primary. The Primary gets a `primary` trail entry and the
  others get `copy` entries. Phase 2's ADM/OCM routing reuses the same request.
- Authorization (`DocumentPolicy::process` / `owns`): only the office holding the open `primary` entry may act. New
  ability `receiveCopy` lets a copy office record receipt (`received_by`/`received_at`) without acting.
- Inbox (`TransactionController` + `transactions/Index.vue`): a separate "Copies (view only)" list with a Receive
  button; no Route/End. Bell (`IncomingDocumentService`): pings copy offices until they receive.
- Completion (`TransactionService::process`): the document completes when the Primary office ends it; the copies are
  closed with it or stay open, depending on the user's answer.
- SLA: delinquency counts only the Primary office's open entry, which also resolves known issue #17.
- Display: `holders` / Show.vue / documents list label each office Primary or Copy.
- Tests: dispatch validation (primary must be a target), copy office cannot route/end, copy receive, completion, bell,
  delinquency on the Primary only.

**Design consequences:** category is a per-document field defaulted from the type (blank or "varies" types need
Records/AI to choose; Supervisory can change it). Classification dropdown is filtered by the type (new pivot
table). Rulebook values are seeded but editable in the Document Types registry, since several are still blank.

**Decided (user, 2026-10-22):** (a) Confidential = Yes on a type means *may be* confidential: Records ticks it per
document (the Phase 1 behaviour stays). (b) The role mapping above is confirmed. (c) The fresh-data reset runs at the
start of Phase 2. (d) PNPKI uses **per-signer certificates** (City Administrator, Mayor, Department Heads each hold
their own), designed at the start of Phase 2; the single certificate in `config/digital-certificate.php` is replaced.
(e) The **System Administrator** uploads each signer's `.p12` (User Management), not the signer. (f) The referral slip is
**removed entirely** in Phase 2 (pages, routes, print template, ADM Dept Head signing step).
**Still open:** whether the certificate password is typed at every signing or stored encrypted — the user wants to
finish pending **Phase 1 changes** before deciding; Phase 2 does not start until then.
Facts found 2026-10-22: the local DB already has 0 documents / 0 trails, but `storage/app/public/documents` (10 files)
and `storage/app/private/signed-documents` (4 files) still hold old files, so the Phase 2 reset should be an artisan
command that also clears these (needed for other environments too). `storage/app/certificates/` already holds one
person's PNPKI bundle (`GUALBERTO+HERMENEGILDO+ANDAYA.p12/.crt/.pem`) next to the shared `certificate.pem` +
`private.key`; it can become the first per-signer certificate.

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
17. ~~SLA/delinquency evaluates only `department_id`; with multi-office copies it reflects one holder.~~
   **Resolved 2026-10-22:** only the Primary office acts and `department_id` is always the Primary, so delinquency
   counting the Primary only is the intended rule.

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
8. Remove dead code (#6–#8, and the unused `DocumentAnalysisService`, an old copy of the intake prompt now
   replaced by `DocumentIntakeAnalyzer`) in a small cleanup change with tests.

## 8. Decisions & assumptions

- Decision (existing code): optimistic concurrency via `workflow_version` + pessimistic row locks inside `mutate()`.
- Decision (existing code): executive approvals keyed by department code, not IDs.
- Assumption: `status.md` is user-maintained and authoritative for feature intent; this file
  documents architecture and handoff state and does not duplicate its feature checklist.
- Assumption: local environment is XAMPP on Windows (path `C:\xampp\htdocs\dms`).

## 9. Change log

### 2026-10-29 — Documents list: aligned actions, Delete hidden (Claude Code, uncommitted)
- `documents/Index.vue`: the "With CHO" / "Pending City … Approval" text moved from the Actions column to under the
  status, so it no longer pushes the buttons around. Actions are same-size 32 px buttons, centered: Route (only when
  Records may route it), then Edit. (An empty placeholder slot was tried first; it left Edit off-centre.)
- **Delete hidden for everyone (user request):** the button, `DeleteConfirmationModal` use and its state were removed
  from the page. The backend (`documents.destroy`, `DocumentService::deactivate`, policy `delete`) is unchanged, so
  it can be brought back by restoring the button. No other document page had a delete button.
- Checks: ESLint ✅; build ✅. Not checked in the browser.

### 2026-10-29 — Document trail: the Primary office's action is the latest row (Claude Code, uncommitted)
- **Why (user):** after End Transaction, the closed copy (e.g. ACA "Closed with document") was listed above the
  Primary's "End Transaction" row. `documents/Show.vue` `latestFirstTrails` now sorts by `created_at` (hand-off time)
  newest first, Primary before copies of the same hand-off, then id; transit math keeps the chronological index.
  ESLint ✅; build ✅. Not checked in the browser.
- **Proxy e-signature replaced by a wet-signature record (user decision 2026-10-29, paper route first):** the
  Process dialog's "Attach Dept. Head Signature (Proxy)" (any staff stamped the head's uploaded image as signer) is
  now **"Signed by the Department Head (wet signature)"** + optional **scan of the signed page**. Any staff of the
  office may record it (no permission needed, as decided). `ProcessDocumentRequest`: `attach_signature` → `head_signed`
  + `signed_scan` (ignored unless ticked). `TransactionService::process()` → `headSignature()`: release gets
  `trail_type` approval, `signed_by` = the office's active Department Head (or the head themself), `signature_mode`
  physical, `signed_file_path` (local disk) when scanned; no `is_digitally_signed`; the scan is deleted if the step
  fails. `DocumentWorkflow::signer()` (image-based) removed → `departmentHeadSigner()` (no image needed; error
  "Your office has no active Department Head account…" when none). Trail badge "Physical Signature" → **"Wet
  Signature"** (tooltip "Signed by hand by {name}"). Inbox page: unused role helpers removed; form sent as FormData.
- Checks: Pest 174 passed (1707 assertions; new `DepartmentHeadSignatureTest`, 4 cases); Pint ✅ (CRLF ignored);
  ESLint ✅; build ✅. Not checked in the browser (dev server down). When paperless returns, the head signs with
  their own PNPKI certificate instead.

### 2026-10-29 — Office Inbox: Receive first, act later (Claude Code, uncommitted)
- **Why (user, testing as a receiving officer):** the inbox only offered "Receive & Route" / "Receive & End"; like
  the old DTS (and like copies), the Primary office must first **Receive**, then act when it is done.
- New `transactions.receive` (`POST /transactions/{document}/receive`, `ReceiveDocumentRequest`, policy `receive`,
  `TransactionService::receive()`): sets `received_by` + `received_at` (actual receipt time) on the office's open
  Primary entry; `received_action` keeps how it arrived (e.g. "Dispatched from Records"). No stale-form check
  (like copy receipt); a second receipt is refused.
- Policy `process` now also requires the office's open Primary entry to be received (legacy rows with no open entry
  may still act). Shared helpers `actsInInbox()` / `openPrimaryEntry()`.
- Bell: unchanged query — the ping now stops at **Receive** instead of at processing.
- `transactions/Index.vue`: unreceived rows show a green **Receive** button; received rows show **Process** with
  "Received by {name}"; the dialog is now "Process Document" with **Route** / **End Transaction**. From the bell the
  row is highlighted; the Process dialog opens automatically only if already received. Inbox eager-loads
  `primaryEntry.receiver`.
- Checks: Pest 169 passed (1669 assertions; inbox/bell test now covers process-before-receive refused, receive, double
  receive refused, receiver shown; workflow/copy/email tests receive before acting); Pint ✅ (CRLF ignored); ESLint ✅;
  build ✅. **Not checked in the browser** (Vite dev server still down).
- Note: Records' own Route button on the documents page uses `dispatch`, which does not require receipt.
- **Plainer inbox (same day, user: the Primary list looked over-designed next to the copies list):** the Primary
  table is now a card like "Copies for your information": heading "Documents for your action" + one-line help;
  columns Barcode, Subject Matter, Source, Action; the Status column and coloured chips are gone (Delayed/Delinquent
  are small coloured text after the document type); actions are small plain buttons — View, then Receive, or the
  "Received" label (tooltip: who received it) + Process; the empty state is one line. ESLint ✅; build ✅.
- **Fixed (user report): no toast after Receive/Process on the inbox.** `TransactionController::index` never passed
  `flash` (old next step #7), so the page's notyf watcher had nothing to show. It now passes
  `flash.success/error`; this also fixes the toasts for Process and Receive copy. New test in
  `IncomingDocumentsTest` (IncomingDocumentsTest 3 passed).

### 2026-10-29 — Hybrid paper route (old DTS style); paperless switched off; OCM routes to departments (Claude Code, uncommitted)
- **Why (user decisions 2026-10-29):** finish the paper route first, like the current DTS. Office staff record the
  executives' approvals; the scan of the signed copy is optional; after the Mayor, documents go straight to the
  departments (FGD Phase 3); paperless is hidden for now.
- **Switch:** `config('services.paperless.enabled')` (env `DMS_PAPERLESS_ENABLED`, empty = off; in `.env.example`).
  Off: `SaveDocumentRequest` saves every document as `paper`; `DocumentType::allowsPaperless()` is false; the Route
  field is hidden on intake and Edit (`useIntakeRulebook` returns `paperlessEnabled`, shared prop
  `features.paperless`); `Document::isPaperless()` (route_mode paperless **and** switch on) decides how approvals
  sign, so documents saved as paperless earlier are approved on paper. All PNPKI code is kept for switching back on.
- **ADM:** `approveAdm` — paper: any ADM user after receipt; paperless: City Administrator / Sys Admin only. Signed
  scan optional on paper. `signed_by` is set only when the executive acts (or paperless); staff show as releaser.
- **OCM:** `viewOcmApprovals` = every active OCM user + Sys Admin (sidebar via shared `auth.canViewOcmApprovals`).
  `approveOcm` — paper: any OCM user; paperless: City Mayor / Sys Admin. `ApproveOcmDocumentRequest` now requires
  `routed_to` + `primary_office_id` (departments except REC/ADM/OCM), optional `remarks`, optional `signed_scan`
  (paper) or PIN + stamp placement (paperless). `OcmApprovalService` records "Approved by City Mayor" (approval
  trail, physical/electronic) and `distribute()`s to the Primary + copies — **no longer returns to Records**, for both
  routes. The controller now redirects back with a flash (was JSON).
- **UI:** new `resources/js/components/ApprovalDialog.vue` (shared by ADM and OCM: departments picker with AI
  pre-ticks, remarks, optional scan or PIN; ADM also offers "City Mayor"); `adm-approvals/Index.vue` uses it (button
  "Record approval" on paper); `ocm-approvals/Index.vue` rewritten in the same style.
- **Bell:** staged entries now ping the whole holding office (any OCM user records the Mayor's paper approval); the
  City Administrator still gets "Ready for City Administrator approval" after ADM receives.
- Checks: Pest 169 passed (1574 assertions; new `OcmApprovalTest` 8 cases; ADM tests: staff record paper approval
  without scan, staff refused on paperless, paperless doc approved on paper while switched off; intake: paperless
  request saved as paper while off; bell test updated). Pint ✅ once CRLF working-copy endings are ignored (same
  files pass at HEAD; git normalizes on commit). ESLint ✅; Prettier ✅ on the new files; build ✅.
  **Not checked in the browser:** the Vite dev server was not running (`public/hot` points to `192.168.4.47:5173`).
- **OCM Receive step (added the same day, user request):** like ADM, any OCM user first clicks **Receive**
  (`ocm-approvals.receive`, `ReceiveOcmDocumentRequest`, policy `receiveOcm`, `OcmApprovalService::receive()` sets
  `received_by/at`, "Received by OCM"); `approveOcm` now requires receipt (this also gates `findStampPlacement`).
  The page shows a Received column and the Receive button. Bell: unreceived OCM entries ping every OCM user; once
  received, the City Mayor gets "Ready for City Mayor approval" (same pattern as ADM; policy helper
  `approvalEntry($document, $stage)` replaces `admApprovalEntry`). Pest 169 passed (1646 assertions; OCM tests now
  receive first and cover the receive/ping flow; stamp-placement tests set up a received paperless entry); Pint ✅;
  ESLint ✅; Prettier ✅; build ✅. Still not checked in the browser (dev server down).

### 2026-10-28 — Linked documents are comma-separated (Claude Code, uncommitted)
- New `resources/js/lib/linkedDocuments.ts` (`barcodeBeingTyped`, `withChosenBarcode`, `linkedDocumentsText`), used by
  `documents/Index.vue` and `documents/Edit.vue`: picking a barcode suggestion gives "C003192158, C003192314, ";
  placeholders updated. The server already splits on commas and spaces (`DocumentService::save`), so no backend change.
- **Fixed on Edit:** `linked_documents` arrives as an array (model cast) and the page called `.trim()` on it; it is now
  shown as comma-separated text.
- Checks: ESLint ✅; Prettier ✅ on the new file; build ✅. Browser (intake page): two suggestions picked in a row gave
  `C003192158, C003192314, `. Edit page not opened in the browser.

### 2026-10-28 — Intake form: numbered steps, confidential and upload first (Claude Code, uncommitted)
- **Why (user request):** Records should see straight away whether a document is confidential and whether it needs
  an upload, and read the form as one continuous flow.
- `resources/js/pages/documents/Index.vue`: the form is now six numbered steps — 1 Is it confidential? (highlighted
  checkbox card) → 2 Attach the scanned document → 3 Document details → 4 Sender (Source Type/Location moved here) →
  5 Subject → 6 Send to + Save. The header "AI Autofill" button and the bottom attachment input are merged into one
  drop zone in step 2 (click or drag; PDF/JPG/PNG): it attaches the file and, unless "Fill the form automatically
  from the file (AI)" is unticked, runs the same OCR + AI analysis as before (`analyzeFile`, was
  `handleFileUpload`). The attached-file card shows reading / "AI filled the form" / a "Fill the form with AI"
  button. Confidential replaces step 2 with a "Skipped" note. The route hint turns amber and points to step 2 when
  paperless has no file. Access Code moved to the header. No server change.
- Checks: ESLint ✅; build ✅. Browser (seeded Records Manager): steps render, confidential toggles step 2, steps 4–6
  shown. **AI upload not re-run in the browser** (same analysis code as before).
- **Compacted the same day (user: whole form in one screen, no scrolling):** steps 1 and 2 side by side (one-line
  confidential card, one-line drop zone, "AI autofill" toggle in step 2's heading); 4-column grids — details: Barcode,
  Document Type, Classification / Transaction Type, Route, Delivery Method, Linked Doc/s; sender: Source Type,
  Source Location, Gender / Source Name, Contact, Email; subject textarea without a visible label (sr-only);
  Direct to, chosen offices and Save on one row. Smaller labels (`text-xs`) and inputs (`py-1`). The form and the
  documents table are now 6/6 columns (was 5/7). Measured at 1366×768: the form ends at 672 px, so it fits without
  scrolling. ESLint ✅.
- **Sizes restored (user: "too compact", keep the layout):** the grid layout stays, but labels are back to `text-sm`,
  inputs to `py-1.5`, gaps to 12 px, step numbers to 20 px, hints to `text-xs`, and the Subject label is visible
  again. Measured at the user's 1913×950 browser viewport: the form ends at 839 px, so it still fits without
  scrolling (at 1366×768 it will scroll a little). ESLint ✅.

### 2026-10-26 — Email Settings page (Claude Code, uncommitted)
- **Why (user request):** set the SMTP account in the app like the old DTS "E-Mail Setting" page, without editing
  `.env`. **Rule exception approved by the user** and recorded in `CLAUDE.md` (Security).
- Migration `2026_10_26_010000_create_mail_settings_table` (**run locally** with `--path`, on its own). Model
  `MailSetting` (`password` uses the `encrypted` cast with APP_KEY and is `$hidden`; `updated_by`).
  `MailSettingsService`: `apply()` overrides `mail.mailers.smtp.*` + `mail.from` and purges the cached mailer (port
  465 → `smtps`, else STARTTLS); `.env` values apply while no row exists or if the table cannot be read (logged);
  `save()` keeps the saved password when the field is blank; `sendTest()`.
- Applied on every web request (`AppServiceProvider::boot`, skipped in console so migrations work) and at the start
  of `NotifyClientOfStatus` (a long-running `queue:work` would otherwise keep the old account).
- Routes `mail-settings.edit/update/test` in the `role:system_administrator` group + FormRequests
  `MailSettingRequest`, `SendTestMailRequest` (both also check active System Administrator). Controller
  `Settings\MailSettingController`; a failed test shows the SMTP error under the field and logs it.
- Page `resources/js/pages/mail-settings/Index.vue` (Administration → **Email Settings**, System Administrator only):
  host, port, account, password (never pre-filled; "leave blank to keep"), sender name and email, last saved by/at,
  and a "Send a test email" box. Before the first save it shows the `.env` values.
- Checks: Pest 159 passed (1465 assertions; new `MailSettingsTest`, 6 cases: access, encryption at rest, password
  never in props, blank keeps, override incl. port 465, test-mail error/success); Pint ✅; ESLint ✅; Prettier ✅;
  Ziggy regenerated; build ✅. Browser: page renders for the seeded System Administrator with the `.env` values and the
  sidebar link active. **Not saved and no email sent** (user will enter a new app password first).

### 2026-10-26 — Client status email; cleaner sidebar (Claude Code, uncommitted)
- **Why (user request):** the old DTS had an "E-Mail Setting" page; the user added Gmail SMTP to `.env` and wants the
  client told the latest status by email (SMS later). The SMTP form was **not** rebuilt: credentials stay in `.env`
  (project rule). The user pasted the Gmail app password in chat — advised to revoke it and create a new one.
- New `ClientStatusService::latest()` (the one status a client may see: "Received by the Records Section", "Being
  processed at the {office}", "For approval of the City Administrator/Mayor", "Completed"; null when deactivated),
  queued job `NotifyClientOfStatus` (3 tries; atomically claims the new status in `documents.client_notified_status`
  so only real changes are emailed and two workers never both send; a failed send releases the claim and is logged),
  mailable `ClientStatusUpdate` + `resources/views/mail/client-status-update.blade.php` (barcode, subject — omitted
  for confidential documents — status, Manila time). Dispatched after commit from `DocumentWorkflow::mutate()` (every
  change to an existing document) and from intake (`DocumentService::save` create) via
  `DocumentWorkflow::notifyClient()`; a queueing error is logged and never fails the workflow step.
- Migration `2026_10_26_000000_add_client_notified_status_to_documents_table` (**pending locally**; plain
  `php artisan migrate` would also run the pending `drop_approved_routing_slip` migration meant for after the reset,
  so either do the reset first or run `php artisan migrate --path=database/migrations/2026_10_26_000000_add_client_notified_status_to_documents_table.php`).
- Needs a running queue worker (`QUEUE_CONNECTION=database`): `composer dev` runs one; production needs
  `php artisan queue:work`. `MAIL_FROM_NAME` is `${APP_NAME}` = "Laravel" locally — set a real sender name.
  `MAIL_ENCRYPTION` is not read by Laravel 12 (port 587 uses STARTTLS automatically).
- SMS not built: needs a provider (e.g. Semaphore or a Globe/Smart gateway), its API key in `.env`, and a decision on
  cost per message. `ClientStatusService` is ready to reuse for it and for the client barcode lookup.
- **Sidebar:** `AppSidebar.vue` + `NavMain.vue` rewritten: labelled sections Documents / Approvals / Administration
  (registries listed flat, no Settings dropdown), current page highlighted in the header blue with a left bar and
  `aria-current`, distinct icons, same role rules as before. `NavItem` lost the unused `roles`/`items`; new `NavSection`.
- Checks: Pest 153 passed (1424 assertions; new `ClientStatusEmailTest`, 6 cases); Pint ✅ on changed PHP; ESLint ✅ and
  Prettier ✅ on the sidebar files; build ✅. Browser: signed in as the seeded System Administrator; sections,
  active highlight (Dashboard, Departments) and collapsed icon mode checked. **No real email sent yet.**

### 2026-10-23 — Dashboard details from the old DTS (Claude Code, uncommitted)
- `DashboardController` + `Dashboard.vue`: **year picker** (first document's year to this year; unknown years fall
  back to the current one); **For Due Tomorrow** and **To-Do List** are their own cards and open drill-downs like
  Delinquent (`?report=delinquent|due_tomorrow|todo`, replacing `?delinquent=1`), with the same office scope;
  **average processing times** take a Simple/Complex/Highly Technical filter and list every active document type of
  that category (by its default transaction type, plus any type with completions) at 0.00 days when none completed,
  in registry order. Pint formatted `DashboardController.php` (pre-existing style issues in that file).
- **Card reports open in a new tab** (`dashboard.report`, `GET /dashboard/report`, page `dashboard/Report.vue`) in the
  old DTS report layout: report type, date range, number of results, all rows (no paging) with Date, Barcode (links
  to `documents.show`), Document Type code, Source Type, Source, Gender, Contact, Email, Subject, Dept and Total Time;
  footer "Generated By" (signed-in user) and today's Manila date; Print button. Titled "DOCUMENT MANAGEMENT SYSTEM
  REPORT"; fixed column widths with wrapping (no horizontal scroll), smaller text and landscape `@page` when printed. The inline dashboard drill-down and
  `?report=`/`?transaction_category=` on `/dashboard` were removed. Card sets now live in `DashboardReportService`
  (shared by both controllers); month/year/category parsing in `DashboardFilterRequest`.
- **Fixed:** total/average days were 8 hours too long: `created_at` is real UTC but `completed_at` is saved as Manila
  wall-clock time (app timezone is UTC); `DashboardReportService::totalDays()` reads `completed_at` as Manila.
  Still open (not changed): month filters use `whereMonth('created_at')` in UTC, so a document registered before
  8 AM Manila on the 1st counts in the previous month.
- Checks: Pest 147 passed (1403 assertions); Pint ✅; ESLint ✅; build ✅. Browser: signed in as the seeded Records
  Manager; the six cards, category switch, On-Going Simple and To-Do reports, and barcode → document page work; no
  console errors after reload.

### 2026-10-23 — Intake routing edge cases (Claude Code, uncommitted)
- `DocumentIntakeAnalyzer::validated()`: `department_id` is discarded unless it is ADM or OCM; routing suggestions
  never include Records, and include OCM only when the document goes to the City Administrator (ADM may forward
  to the Mayor). So the Primary picked by the server is always an office the intake form can route to.
- `documents/Index.vue`: the autofilled Primary is chosen from the filtered offices (it could be OCM/Records and
  fall outside `routed_to`), and a confidential document keeps that Primary instead of the first suggestion.
- Checks: Pest 142 passed (1268 assertions; 3 new tests in `DocumentAnalysisTest`); Pint ✅; ESLint ✅.
  **Not tested in the browser** (needs a real scan and AI call).

### 2026-10-23 — FGD Phase 2e: fresh-data reset command (Claude Code, uncommitted, **not run**)
- New `app/Console/Commands/ResetDocuments.php` → `php artisan dms:reset-documents [--dry-run] [--force]`.
  Deletes all `documents` + `document_trails` rows (one transaction; MySQL auto-increments reset to 1 afterwards)
  and then every file under `storage/app/public/documents/` (intake scans) and
  `storage/app/private/signed-documents/` (approval scans and stamped PDFs). Keeps offices, users, registries,
  People Directory, e-signatures (`public/signatures`), certificates, login history and audit logs (user changes
  only). Safeguards: prints counts first, `--dry-run` changes nothing, typed `RESET` confirmation unless `--force`,
  Laravel's production prompt (`ConfirmableTrait`), files deleted only after the rows, run logged with counts and
  any file it could not delete. This is the only code that deletes trail history (user-approved fresh start).
- Migration `2026_10_23_040000_drop_approved_routing_slip_from_documents_table` (**pending; run after the reset**).
- To do the reset: `php artisan dms:reset-documents --dry-run`, then `php artisan dms:reset-documents`, then
  `php artisan migrate`.
- Checks: Pest 139 passed (1261 assertions; new `ResetDocumentsTest` 5 cases on faked disks); Pint ✅. The command
  was not run against the local database.

### 2026-10-23 — FGD Phase 2d: referral slips retired (Claude Code, uncommitted)
- Migration `2026_10_23_030000_retire_referral_slips` (**not yet run locally**): open `adm_signature` entries move
  to `adm_approval` (they are already at ADM and go through the single approval); closed entries keep their
  history; **drops `documents.requires_signature_from`** (the trail stage is the only source). `approved_routing_slip`
  is no longer read or written but the column stays until the 2e reset, so past slips' targets are not lost first.
- Removed: `ReferralSlipController`, `ReferralService`, `GenerateReferralRequest`, `SignReferralRequest`, the
  Referral Slips page and print/PDF template, routes `referral-slips.*`, policy `viewReferrals`/`signReferral`/
  `viewReferralSlip`/`refer`, `DocumentTrail::STAGE_ADM_SIGNATURE`, shared prop `canViewReferrals` + sidebar item,
  `RoutingModal`'s slip/override modes, the slip link and slip styling in `documents/Index.vue`, `slipTargets`
  on `documents/Show.vue`. Trail columns used by old slips (`required_actions`, …) stay: trail history is kept.
- New `OcmApprovalController` (index, approve, `findStampPlacement`, `previewSigned`) + `OcmApprovalService`;
  policy ability `approveOcm` (stage-based). Routes renamed: `approvals.stamp-placement`
  (`/approvals/{document}/stamp-placement`) and `documents.signed-pdf` (`/documents/{document}/signed-pdf`).
- Fixed: the signed-PDF preview returned the **oldest** signed file (`trails()` sorts ascending; `latest('id')` was
  appended after it); it now shows the newest (e.g. OCM after ADM).
- Intake to ADM now records "Awaiting City Administrator approval"; documents list labels read "Pending City
  Administrator Approval" / "Pending City Mayor Approval"; login page feature line updated.
- Checks: Pest 134 passed (1213 assertions; 6 slip-only tests removed, the rollback test now covers ADM approval
  incl. file cleanup, new tests for the 2d migration and the newest-signed preview); Pint ✅; ESLint ✅; build ✅.
  **Not tested in the browser.**

### 2026-10-23 — FGD Phase 2c-2: per-signer PNPKI certificates (Claude Code, uncommitted)
- Migration `2026_10_23_020000_add_signing_certificate_to_users_table` (**not yet run locally**):
  `users.certificate_path` (hidden from serialization, set only by the service), `certificate_subject`,
  `certificate_valid_until`. New private disk `certificates` (root `storage/app/certificates`, `serve` false;
  git-ignored by `storage/app/.gitignore`). `User::SIGNER_ROLES`, `hasValidCertificate()`.
- `SigningCertificateService`: upload checks the `.p12` opens with its PIN, has a private key and is not expired,
  then stores `users/{id}.p12`; `credentials()` opens it in memory for one signing; clear messages for a wrong
  PIN, a non-certificate file and legacy encryption OpenSSL 3 cannot read. **The PIN is never stored**; it is in
  `dontFlash` (`bootstrap/app.php`) and the pages clear it after every submit.
- Settings → **Signing certificate** (`settings/certificate`, `SigningCertificateController`,
  `SigningCertificateRequest`, page `settings/Certificate.vue`): self-service upload/replace/remove for approver
  roles (City Administrator, City Mayor, Department Head, Sys Admin).
- `PdfApprovalStampService::stamp($document, $signer, $placement, $office, $pin)`: always the **signer's own**
  certificate (PEM strings in memory, chain via a short-lived temp file) + the signer's own e-signature image
  (`users.signature_path`, uploaded in User Management). ADM paperless approvals are now digitally signed.
- **Removed `config/digital-certificate.php`** (the single City Mayor certificate). The files in
  `storage/app/certificates/` were left untouched; `DMS_CERTIFICATE_PRIVATE_KEY_PASSWORD` in `.env` is unused.
  **The City Mayor must upload their own `.p12` in Settings and have an e-signature image in User Management
  before OCM approvals work again.**
- PIN prompts: ADM Approve & Route dialog (paperless) and a new PIN dialog on OCM Approvals; both pages warn
  when the approver has no valid certificate.
- Not verified: whether the real PNPKI `.p12` files open under OpenSSL 3 (the one on disk did not open with the
  old private-key password, which is expected: its PIN differs). A legacy-encrypted file gets a clear message.
- Checks: Pest 139 passed (1304 assertions; new `SigningCertificateTest` 6 cases; ADM paperless tests now sign
  with a generated certificate and check `/ByteRange` + `/Type /Sig`; `testCertificate()` helper in
  `tests/Pest.php`); Pint ✅; ESLint ✅; build ✅. **Not tested in the browser.**

### 2026-10-23 — FGD Phase 2c: ADM single approval (Claude Code, uncommitted)
- New page **ADM Approvals** (`adm-approvals.index/receive/approve`, `AdmApprovalController`,
  `AdmApprovalService`, `ReceiveAdmDocumentRequest`, `ApproveAdmDocumentRequest`, page
  `resources/js/pages/adm-approvals/Index.vue`, sidebar item via shared `auth.canViewAdmApprovals`).
- Policy: `viewAdmApprovals` (active ADM users + sys admin), `receiveAdm` (ADM entry not yet received),
  `approveAdm` (City Administrator or sys admin, only after receipt).
- Flow: any ADM user **Receive** → `received_by/at`, action "Received by ADM"; City Administrator **Approve &
  Route** → release "Approved by City Administrator" (trail_type approval, `signed_by`, `signature_mode`
  physical/electronic, `signed_file_path`, `released_to_offices`) → OCM (`ocm_approval` stage) or departments
  (`distribute()`, Primary + copies). Never back to Records. Paper: wet-signed scan required (local disk
  `signed-documents/`). Paperless: PDF stamped. Files written before a failure are deleted.
- **Stamp service:** `PdfApprovalStampService::stamp(..., $office)` with `OCM` (unchanged: City Mayor's PNPKI
  certificate) and `ADM` profiles. **ADM has no certificate yet:** it draws the City Administrator's own
  uploaded e-signature with "Electronically Signed" and no cryptographic signature (the configured certificate
  is the Mayor's, so using it for ADM would be wrong). Per-signer certificates = step 2c-2. The service now
  stamps the newest signed PDF, so OCM after a paperless ADM approval keeps the ADM signature; signature images
  may be PNG or JPEG. `Document::stampSourceUrl()` gives both pages the same PDF to measure placement on.
- Stamp placement logic moved from `ocm-approvals/Index.vue` to `resources/js/lib/stampPlacement.ts`;
  `findStampPlacement` accepts OCM (`refer`) and ADM (`approveAdm`).
- Bell: an `adm_approval` entry pings every ADM user until received, then the City Administrator
  ("Ready for City Administrator approval", links to ADM Approvals). Referral Slips page now lists only legacy
  `adm_signature` documents (empty for the City Administrator).
- Checks: Pest 130 passed (1239 assertions; new `AdmApprovalTest` 10 cases incl. a real PDF stamp; bell and
  queue expectations updated in IncomingDocuments/ExecutiveStage/WorkflowReliability); Pint ✅ on new/changed
  PHP; ESLint ✅; build ✅. **Not tested in the browser.**

### 2026-10-23 — FGD Phase 2b: intake "Direct to" Departments (Claude Code, uncommitted)
- `SaveDocumentRequest` (create only): `routed_to[]` (active offices except REC/ADM/OCM) + `primary_office_id`
  (required with `routed_to`, must be one of them); `department_id` prohibits `routed_to` (ADM/OCM **or**
  departments); both prohibited on update.
- `DocumentService::save` create: with `routed_to`, Records registers, releases ("Dispatched to target office(s)",
  `released_to_offices`) and `DocumentWorkflow::distribute()` gives the Primary entry + view-only copies (no
  stage); confidential + more than one office is refused by `distribute()` and nothing is saved.
- New `resources/js/components/OfficePicker.vue` (two panels, Primary radio, AI reasons, single-office mode for
  confidential); `RoutingModal.vue` now uses it instead of its own panels.
- `documents/Index.vue`: Direct to = City Administrator / City Mayor / **Departments**; Departments shows the
  chosen codes (Primary marked) and opens the picker in a dialog. AI: OCM/ADM targets pick those; no executive
  target with suggested offices → Departments with them pre-ticked and the AI's Primary pre-selected.
- Checks: Pest 120 passed (1114 assertions; new `IntakeToDepartmentsTest`, 8 cases); ESLint ✅ on the 3 files;
  build ✅; Pint ✅ on changed PHP. **Not tested in the browser** (in-app browser cannot load the LAN Vite server).

### 2026-10-23 — FGD Phase 2a: explicit stage on the open Primary entry (Claude Code, uncommitted)
- Migration `2026_10_23_010000_add_stage_to_document_trails_table` (**not yet run locally — the app needs it,
  since `Document` now appends `stage`**): nullable indexed `document_trails.stage`; backfills the newest open
  Primary entry of active documents from `requires_signature_from` (city_administrator → `adm_approval`,
  department_head → `adm_signature`, city_mayor → `ocm_approval`).
- `DocumentTrail::STAGE_*` + `EXECUTIVE_STAGES` (replaces `Document::EXECUTIVE_STAGES`). `Document`:
  `primaryEntry()` (latest open Primary), `stage` accessor (uses the eager-loaded entry, else reads fresh),
  `inExecutiveStage()`, scopes `inStage()` / `outsideExecutiveStages()`.
- Writes: `DocumentWorkflow::receive(..., $stage)` (copies never get a stage), `executiveStageFor()` (ADM/OCM by
  code); intake, registration reassign and slip generation set it. `requires_signature_from` is still written
  everywhere (transitional) but **no longer read**; drop it in 2d.
- Reads switched: `DocumentPolicy` (dispatch, process, refer, signReferral), `TransactionController` inbox,
  `ReferralSlipController` queues and stamp check, `ReferralService`, `IncomingDocumentService` (bell filters on
  the entry's own stage), pages `documents/Index.vue`, `documents/Show.vue`, `referral-slips/Index.vue`.
- Behaviour change: **changePrimary is refused while an approval stage is pending** (moving the Primary office
  would have skipped the approval).
- Checks: Pest 112 passed (1054 assertions; new `ExecutiveStageTest` 5 cases incl. the backfill; stage asserts
  added to WorkflowReliability; OcmStampPlacement fixture now sets the trail stage); ESLint ✅ on the 3 pages;
  build ✅; Pint: no new violations (the touched legacy files keep their pre-existing style debt).

### 2026-10-23 — Intake page: leaner layout in the old DTS style (Claude Code, uncommitted)
- **Why (user request):** keep the current form but make it plain like the previous DTS ("remove the blue").
- `resources/js/pages/documents/Index.vue` template only (script logic unchanged apart from three style helpers
  `labelClass`, `fieldClass()`, `iconButtonClass` and the unused `LinkIcon` import removed): square-ish fields,
  no shadows or tinted panels, the indigo "Route to …" select is now a plain **Direct to** select, the indigo AI
  box is now a one-line "AI suggests …" hint under it, Classification gets its own wider field, confidential is
  a plain checkbox row, table status chips became plain text (Delayed/Delinquent stay coloured), action buttons
  are neutral (Delete stays red). Accessibility: every label is now tied to its field, errors use `InputError`
  with the real message, icon buttons have `aria-label`, the barcode suggestions are buttons.
- Checks: ESLint ✅ on the page; build ✅; Pest 107 passed. **Not viewed in the browser** (the in-app browser
  blocks the LAN Vite server and needs a sign-in).

### 2026-10-23 — People Directory: complete sender names hidden under signatures (Claude Code, uncommitted)
- **Why (user report after browser test):** the OT memo still gave "Atty. R. L. Caloza"; "GILBERT" sits under the
  handwritten signature and is not in the OCR text, so no model can read it. Everything else on both scans was
  correct; the Junalyn sender is "almost all correct" (what is still off was not stated yet).
- New table `people` (migration `2026_10_23_000000_create_people_table`, **not yet run locally**): title,
  first_name, middle_initial, last_name, suffix, position, department_id, state. Model `Person` (`full_name`
  accessor, e.g. "Atty. Gilbert L. Caloza"), `PersonController` + `SavePersonRequest`, route resource `people`
  (registry group: System Administrator + Records), page `resources/js/pages/people/Index.vue`, sidebar
  Settings → People Directory.
- `DocumentIntakeAnalyzer::directoryMatch()`: after the surname repair, active entries with the same surname
  are scored: a readable word (3+ letters) must be one of the person's first names, short tokens count as
  initials, and a name whose initials all disagree is rejected. Exactly one best match → its full name, and its
  office sets source_type Internal + source_location (so that office is also excluded from routing). Ties or no
  match → name left as read.
- Directory errors no longer fail the autofill: a `QueryException` in the lookup is logged ("People directory
  lookup failed during intake analysis.") and the name is left as read. Found in the browser test: the
  migration was still pending, so every upload returned "AI Analysis failed". Pest 107 passed after this.
- Not done: routing from the directory (e.g. "Attn: Engr. Germie O. Deang" → his office as Primary); gpt-6-luna
  already routes the Q-DRIVE sample to CICTO.
- Checks: Pest 106 passed (1012 assertions; new `PeopleDirectoryTest`, 6 cases); Pint ✅; ESLint ✅ and
  Prettier ✅ on the new page; build ✅. Not checked in the browser (migration pending).

### 2026-10-23 — Intake AI: referral-slip signer, split surnames, subject names the invitee (Claude Code, uncommitted)
- **Why (user report, Q-DRIVE panel invitation):** sender came out "Atty. RT L. Caloza" (the City Administrator,
  who signs the attached referral slip) or "Junalyn F. Ayan" (surname split by OCR under the signature); the subject
  named only the Mayor although the invitation is for Mr. Kenneth Jay D. Bruno.
- `DocumentIntakeAnalyzer`: pages matching "Referral/Action/Routing Slip" now reach the model as their From/Subject
  lines only (`withoutReferralSlips()`, before the length cut). New `sourceName()` post-check: repairs a surname
  from a longer spelling on a line with the same first name (e.g. the slip's "From: JUNALYN PANGAYAN"), and drops a
  name whose surname is not in the text the model saw. Prompt: the subject summary names the person the request is
  really for (Attn / "and/or Mr. X"). Routing suggestions are answered as `"CODE Name"` strings (reason first) and
  mapped back to ids server-side; bare ids made gpt-4o-mini pick the lowest ids (ABEO, CHO).
- Live check (3 runs each on text I typed from the user's screenshot; slip page and signature block are my
  approximations): sender "Junalyn F. Pangayan" 3/3, subject names Kenneth Jay D. Bruno 3/3, intake target OCM 3/3.
  **Still wrong:** Primary office (CDO/CSD/CHO instead of CICTO) on gpt-4o-mini; `gpt-4.1-mini` picked CICTO 2/2 but
  returned no sender once. A people directory (name → office) is the reliable fix. Prompt ~2,970 tokens/call (+300).
- Checks: Pest 98 passed (985 assertions; 4 new DocumentAnalysisTest cases); Pint on changed files ✅. Not tested
  with a real scan in the browser.
- **Model upgrade (same day, user request):** default intake model is now `gpt-6-luna` with
  `reasoning_effort` `none` (`config/services.php`; env `DMS_INTAKE_AI_MODEL`, new `DMS_INTAKE_AI_REASONING_EFFORT`
  in `.env.example`). `DocumentIntakeAnalyzer::modelParameters()` sends `temperature: 0` to older models and
  `reasoning_effort` (no temperature) to gpt-5+/o-series, which reject temperature. Comparison on the same
  Q-DRIVE sample, 7 checks (sender, External, subject, OCM, type, classification, Primary CICTO), 2–3 runs each:
  gpt-6-luna 7/7 every run at effort none (~2,775 in / ~175 out tokens, 2–4 s), low and medium also 7/7 but 2–5×
  the output; gpt-4o-mini 6/7 (Primary CSD); gpt-5.6-luna 6/7; gpt-5.4-mini/nano 6/7 (sender format);
  gpt-5-mini/nano 2–5/7 and slower. `minimal` effort is rejected by gpt-6-luna. Price per third-party trackers
  (not confirmed on openai.com): $0.10 in / $0.50 out per 1M → ~$0.00036 per scan vs ~$0.00053 for gpt-4o-mini.
  **Caveat:** one sample only, typed from a screenshot; re-check with real uploads (the OT memo sample too).
- Checks after upgrade: Pest 100 passed (989 assertions; new model-parameter test); Pint ✅.

### 2026-10-22 — Intake AI autofill: more accurate, half the tokens (Claude Code, uncommitted)
- **Why (user report):** autofill gave the wrong sender name, left fields empty, ignored the rulebook, and gave
  different results on laptop vs PC. Causes found with two real scans: only page 1 was OCR'd (both signatures are on
  page 2; one page 1 was the attached referral slip); a low-detail image cost ~2.8k tokens for no gain; the prompt had
  no rulebook (type → category/classifications); free-form JSON allowed invented ids; OCR misread the barcode.
- New `app/Services/DocumentIntakeAnalyzer.php` (see §3). `DocumentController::analyze()` now only calls it (old
  inline prompt, image handling and private `routingSuggestions()` removed). `AnalyzeDocumentRequest`: `ai_thumbnail`
  removed. `config/services.php` `intake_ai.model` (env `DMS_INTAKE_AI_MODEL`, empty = `gpt-4o-mini`) and
  `intake_ai.max_text_characters` (9000); `.env.example` key added.
- Frontend: new `resources/js/lib/intakeScan.ts` OCRs pages 1, 2 and last with one tesseract worker ("--- Page N ---"
  markers) and decodes the Code128/39 sticker with `@zxing/browser` (already installed). `documents/Index.vue` sends
  JSON `{extracted_text}` only; the decoded sticker beats the AI barcode; a barcode already typed is never overwritten.
- **Scores** (scratchpad harness, 2 samples × 3 runs × 9 fields; expected answers are my assumptions, to be confirmed
  by the user): old code 57% at ~6,200 tokens/call (7–13 s) → new 70% at ~2,970 tokens/call (2–4 s). `gpt-4.1-mini`
  scored the same 70%; `gpt-4.1-nano` 61%, so `gpt-4o-mini` stays the default.
- Remaining misses: a first name hidden under a handwritten signature ("GILBERT" Caloza) cannot be read from OCR text;
  a garbled surname ("Ayan" vs "Pangayan") is only sometimes repaired; one sample's Primary office (CHO vs CICTO)
  and the executive target of an ADM-authored memo vary. A directory of office heads' real names would fix the first.
- Device differences: tesseract.js picks its WASM core per CPU, so OCR can still differ between machines (not pinned).
- Checks: Pest 94 passed (976 assertions; `DocumentAnalysisTest` rewritten, 12 cases); Pint on changed PHP ✅ (the
  controller keeps its pre-existing style debt); ESLint ✅; Prettier: `Index.vue` already failed at HEAD; build ✅.
  **Not tested in the browser** (upload → autofill end to end).
- **Paused 2026-10-22, resume next session:** the user says some expected answers are wrong; corrections not yet
  given. Assumed answers — s1 (`…202609021128234.pdf`, OT memo): C003192314, Internal, ADM, "Gilbert … Caloza",
  Communications, Request for OT, no executive target, Primary CHO, subject has "OT-402-2026" + "overtime".
  s2 (`…2026101208464122.pdf`, panel invitation): C003196677, External, "Junalyn F. Pangayan", Communications,
  Invitation, executive OCM or ADM, Primary CICTO, subject has "panel" + "Q-DRIVE". Next: get corrections → update
  the harness answers → re-score → tune the prompt → browser-test an upload. Harness (render.py, ocr.mjs,
  analyze.php, score.py, samples.json) is in this session's temp scratchpad and may need rebuilding.

### 2026-10-22 — Multi-office routing: Primary office acts, others get view-only copies (Claude Code)
- **Why:** user request; replaces the 2026-10-13 rule that every routed office gets an actionable copy.
- Migration `2026_10_22_000000_add_copy_role_to_document_trails_table` (`copy_role` enum primary|copy, default
  primary). Applied locally (confirmed later on 2026-10-22 with `php artisan migrate:status`).
- `DocumentWorkflow`: `openOffices($role)`, `primaryOffice()`, `closeCopies()` (no receiver recorded for unreceived
  copies), `distribute()` (Primary + copies; a copy holder made Primary has its copy closed; confidential = one office
  only; Primary must be a selected office); `receive()` takes the role; `release()` prefers the office's Primary entry.
- `DispatchService` / `DispatchDocumentRequest`: optional `primary_office_id` (default first target).
  `TransactionService::process` / `ProcessDocumentRequest`: only the Primary office acts; end completes the document
  and closes copies; route accepts several offices + `primary_office_id` (a single id still works).
  New `receiveCopy` and `changePrimary` (+ `ReceiveCopyRequest`, `ChangePrimaryOfficeRequest`, routes
  `transactions.receive-copy`, `documents.primary-office`, Ziggy regenerated).
- `DocumentPolicy`: `owns()` counts the Primary entry only; new `receiveCopy`, `changePrimary` (Records roles +
  city_administrator, city_mayor, department_head). `Document::scopeHeldBy` = Primary only; new `scopeCopiedTo`;
  `holders` carries `pivot.copy_role`, Primary first. Delinquency already used `department_id` (= Primary), which
  resolves known issue #17.
- Bell: copy offices are pinged until they receive; the link opens the inbox with that copy highlighted.
- UI: RoutingModal marks one office Primary (radio; AI Primary pre-selected; confidential = one office); Office
  Inbox has "Copies for your information (view only)" with Receive copy, and routing picks a next Primary plus
  optional copies; document page shows Primary + copies and a "Change Primary office" control; documents list only
  offers Route to the Primary office.
- Tests: multi-office test rewritten for the new rule; new `tests/Feature/PrimaryCopyRoutingTest.php` (5 tests).
  Pest 90 passed (967 assertions); Pint ✅ on new/changed files except pre-existing issues in `DocumentPolicy.php`,
  `Document.php`, `DocumentTrail.php`; ESLint ✅; build ✅. Not checked in a browser.

### 2026-10-21 — FGD Phase 1: Records intake follows the rulebook (Claude Code)
- Migration `2026_10_21_010000_add_confidential_and_route_mode_to_documents_table`: `documents.is_confidential`
  (bool, default false), `documents.route_mode` (paperless|paper, default paper). **Applied locally**; existing
  documents became non-confidential / paper.
- `SaveDocumentRequest`: new fields (omitted on update = keep current values); `after()` rulebook checks —
  confidential ⇒ no scan (new or existing) and no AI routing suggestions; paperless ⇒ type signed `digital`, not
  confidential, and a scan present (new or existing); classification must be in the type's list when it has one.
  `DocumentType::allowsPaperless()` holds the rule.
- **Decision (assumption, confirm):** the confidential tick is available for every type; "may be confidential" on the
  type only shows a hint. Blocking a tag was judged riskier than allowing it (e.g. AICS vouchers).
- Forms: new composable `resources/js/composables/useIntakeRulebook.ts` (used by `documents/Index.vue` and
  `documents/Edit.vue`): category pre-filled from the type's default, classification list filtered, route set to the
  rulebook default (Records may choose paper), confidential disables AI Autofill and the upload and clears AI results.
  List and `Show.vue` show Confidential + Paper/Paperless badges. `DocumentController` sends each type's rulebook entry.
- New `tests/Feature/RecordsIntakeTest.php` (6 tests). Pest 85 passed (907 assertions); Pint ✅ on changed/new PHP;
  ESLint ✅; Prettier ✅ on the composable; build ✅.
- **Browser-checked** (records_manager on a temporary `php artisan serve` at 127.0.0.1:8001, nothing submitted):
  Payroll → Complex + Paper (Paperless disabled) + AICS/Financial only; Communications → Paperless + 17
  classifications + hint; ticking Confidential → Paper, AI Autofill disabled, upload replaced by a note.
  Added `.claude/launch.json` (`dms-local`, port 8001) for that preview.
- Known: picking a type with no default category keeps the previously selected category; the AI analyze endpoint
  cannot tell confidential intake server-side (the form blocks it; the save rejects AI suggestions).

### 2026-10-21 — FGD Phase 0: document-type rulebook, 24 h delinquency, registry access (Claude Code)
- **Why:** the DMS moves to the FGD process (deck + rulebook sheet; see §5 "FGD process transition").
- Migration `2026_10_21_000000_add_rulebook_columns_to_document_types_table`: `document_types.signature_type`
  (digital|handwritten, nullable), `may_be_confidential`, `default_transaction_type_id` (FK, null on delete),
  `rulebook_remarks`; pivot `document_classification_document_type` (FK names shortened: MySQL's 64-char limit
  broke the first local run, which left a partial schema that was removed before re-running); default
  `performance_standards.allocated_minutes` 7200 → 1440. **Applied locally**; rollback + re-apply verified on MySQL.
- New `DocumentTypeRulebookSeeder` (called by `DatabaseSeeder`) with the sheet's answers; run locally (32 links).
- `DocumentType` relations `classifications()`, `defaultTransactionType()`; new `SaveDocumentTypeRequest`;
  `DocumentTypeController` saves + syncs classifications in a transaction and passes category/classification lists.
- `document-types/Index.vue`: Signature, May Be Confidential, Default Category, Route (auto), Classifications
  columns; modal edits them (labelled inputs, `InputError`, dialog `aria-*`, Esc closes).
- `PerformanceStandard::DEFAULT_ALLOCATED_MINUTES = 1440` used by `Document::is_delinquent` and the standards grid
  (the grid used to pre-fill 7200 and would save it on submit).
- **Bug fixed:** `is_delinquent` read Manila wall-clock `released_at` as UTC (8 h error; known issue #15). It now
  converts each start time to real Manila time. Found by the new 24 h test.
- Routes: `users` = `auth` + `role:system_administrator`; other registries = `auth` +
  `role:system_administrator,records_manager` (admin groups previously had no `auth`). Sidebar shows Settings to
  Records Manager without User Management.
- New `tests/Feature/DocumentTypeRulebookTest.php` (access matrix, save/sync, validation, seeded rulebook,
  24 h delinquency). Pest 79 passed (863 assertions); Pint ✅ on new files; ESLint ✅; build ✅. Pre-existing Pint
  issues remain in `Document.php`, `PerformanceStandard.php`, `PerformanceStandardController.php`, `routes/web.php`
  (not reformatted to keep the diff small). Not viewed in a browser.

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
