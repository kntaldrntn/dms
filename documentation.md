# DMS — Project Documentation & Development Handoff

> Persistent handoff file. Read before continuing work; update at milestones, before
> handoff, and before `/compact`. Mark facts as **verified** only with evidence.
> This file is the single source of project and feature status (user decision 2026-10-30); `status.md` is
> outdated (2026-10-09) and is disregarded.

Last updated: 2026-10-30 (sections 1–9 rewritten to match the code; client tracking; dashboard by department;
category change by role; list buttons by permission; intake requires a destination; city seal in emails).

---

## 1. Overview

Internal Document Management / Tracking System for the City of San Fernando, La Union (Philippines), built around
the FGD process (focus group discussion deck, see §6).

1. **Records intake:** Records registers each incoming document by barcode, optionally with AI autofill from the
   scanned file (OCR in the browser, extraction on the server). The document-type rulebook sets the category,
   allowed classifications and route (paper/paperless). Records picks **Direct to**: City Administrator (ADM),
   City Mayor (OCM) or Departments (one **Primary** office + view-only copies).
2. **Executive approvals:** ADM and OCM staff **Receive**, then record the executive's approval (paper route: any
   office user records it, optional scan of the wet-signed copy). ADM routes on to OCM or departments; OCM routes
   to departments. Nothing goes back to Records.
3. **Office processing:** the Primary office **Receives**, then **Processes** (Route to the next Primary + copies,
   or End Transaction), optionally recording the Department Head's wet signature. Copy offices only receive and view.
4. **Custody trail:** every hand-off is a `document_trails` row; the trail is never rewritten.
5. **Monitoring:** dashboard with SLA cards (24 h delinquency per office, delay by category), printable reports.
6. **Clients:** get one email with the barcode + access code and look up the **latest status only** on the sign-in
   page (Guest tab). SMS later.

The paperless route (PNPKI per-signer digital signatures, AI stamp placement) is built but **switched off**
(`DMS_PAPERLESS_ENABLED`) until the paper route is settled. It is still a required feature (user, 2026-10-30): keep
it working and tested, and design new approval work for both routes.

## 2. Verified stack

| Layer | Technology | Evidence |
| --- | --- | --- |
| Backend | Laravel 12.66.0, PHP 8.2.12 locally (CI uses 8.4) | `php artisan --version`, `php -v` (2026-10-13) |
| Frontend | Inertia 2, Vue 3.5, TypeScript 5, Vite 6, Tailwind 3, radix-vue/shadcn-style components, lucide icons, notyf toasts | `package.json`, `components.json` |
| Routing helper | Ziggy (`resources/js/ziggy.js` is generated; regenerate after route changes) | `composer.json` |
| DB | MySQL (db `dms`) locally via XAMPP; SQLite `:memory:` for tests | `.env`, `phpunit.xml` |
| Queue / cache / session | `database` drivers. **The queue needs a running worker** (emails) | `.env` |
| Mail | SMTP (Gmail app password) from `.env`, overridable in Email Settings (`mail_settings`) | `MailSettingsService` |
| AI | `openai-php/laravel`: intake extraction (`DocumentIntakeAnalyzer`, default `gpt-6-luna`, effort `none`); `gpt-4o-mini` vision for stamp placement (paperless only) | `config/services.php`, `OcmApprovalController` |
| Client-side OCR/scan | tesseract.js, pdfjs-dist, @zxing (barcode) | `package.json` |
| PDF | setasign/fpdi-tcpdf + tecnickcom/tcpdf (approval stamps + PNPKI signatures); smalot/pdfparser; barryvdh/laravel-dompdf (installed but unused since referral slips were removed; no reference in `app/`, `routes/`, `config/` or views, checked 2026-10-30) | `composer.json` |
| Tests | Pest 3 + pest-plugin-laravel; `RefreshDatabase` on Feature tests | `tests/Pest.php` |
| Node | v26.8.1 / npm 12.0.2 locally (CI uses Node 22) | `node -v` (2026-10-13) |

Unknown / unverified: production hosting, production DB engine/version, how the queue worker will run in production.

## 3. Architecture

Request flow: **route → middleware (`auth`, `verified`, `role:`, `working_hours`) → controller → FormRequest
(authorize + rules) → service → `DocumentWorkflow` → models/trails.**

### Key backend modules

| Path | Responsibility |
| --- | --- |
| `routes/web.php` | Documents, transactions (Office Inbox), ADM/OCM approvals, dashboard + report, registries, settings. Processing routes sit in `working_hours` (Mon–Fri 08:00–17:00 Asia/Manila; system admins bypass). `users` and Email Settings: `role:system_administrator`; other registries: `role:system_administrator,records_manager`. |
| `routes/auth.php` | Login/password routes (guest) + the **public** `POST /track` client lookup (user-approved exception, see CLAUDE.md). `/register` still exists (known issue). |
| `app/Services/DocumentWorkflow.php` | Core concurrency/custody engine. `mutate()` locks document + user, authorizes via policy, rejects inactive/completed docs (409), checks `workflow_version`, increments it, retries deadlocks up to 3×. Also `targets()`, `office(code)`, `release()`, `receive()`, `distribute()` (Primary + copies), `openOffices()`, `closeCopies()`, `executiveStageFor()`, `departmentHeadSigner()`. |
| `app/Services/DocumentService.php` | Create/update/deactivate. Create: ADM/OCM target → custody moves there with the approval stage; Departments → `distribute()`; queues the tracking-code email. Update queues it again when the client email is added/changed. Deletes the uploaded file on failure. |
| `app/Services/TransactionService.php`, `DispatchService.php` | Office Inbox: `receive()` (Primary must receive before acting), `process()` (route/end, wet-signature record), `receiveCopy()`, `changePrimary()`. Records' Route button uses `dispatch`. |
| `app/Services/AdmApprovalService.php`, `OcmApprovalService.php` | Receive + approve for each executive stage; paper: optional signed scan (`local` disk); paperless: `PdfApprovalStampService`. ADM → OCM or departments; OCM → departments. |
| `app/Services/PdfApprovalStampService.php`, `SigningCertificateService.php` | Paperless only: stamp + PNPKI-sign with the **signer's own** `.p12` (`certificates` disk, `users/{id}.p12`; PIN typed at each signing, never stored). Output in `storage/app/private/signed-documents`. |
| `app/Services/DocumentIntakeAnalyzer.php` | Intake AI (called by `DocumentController::analyze()`): OCR text of pages 1, 2, last → strict JSON with registry ids + rulebook; re-checked server-side; People Directory name match. |
| `app/Services/IncomingDocumentService.php` | Notification bell: open trail entries not yet received at the user's office, per stage/role. Shared lazily as `incoming`. |
| `app/Services/ClientStatusService.php` | The one status a client may see (`latest()`), and `track(barcode, access code)` for the public lookup (constant-time code compare; never the trail). |
| `app/Jobs/SendTrackingCode.php`, `app/Mail/DocumentTrackingCode.php` | Queued email with barcode + access code (+ subject unless confidential) and a link to `/login?tab=track`; the city seal is embedded inline. `resources/views/vendor/mail/html/{header,message}.blade.php` override the framework's email header/footer for every email (city name instead of the Laravel logo). |
| `app/Services/MailSettingsService.php` | Email Settings: applies the saved SMTP row (encrypted password) over `.env` per request and in mail jobs; a blank first save takes `MAIL_PASSWORD` from `.env`. |
| `app/Services/DashboardReportService.php` | Dashboard card sets + printable reports (`dashboard.report`). |
| `app/Console/Commands/ResetDocuments.php` | `php artisan dms:reset-documents [--dry-run] [--force]` — deletes all documents, trails and their files (run once on 2026-10-30). |
| `app/Services/DocumentAnalysisService.php` | **Unused** older copy of the intake prompt (known issue). |
| `app/Policies/DocumentPolicy.php` | `viewAny`, `view`, `create`, `update`, `delete`, `dispatch`, `process`, `receive`, `receiveCopy`, `changePrimary`, `changeCategory`, `viewAdmApprovals`, `receiveAdm`, `approveAdm`, `viewOcmApprovals`, `receiveOcm`, `approveOcm`. Ownership = the office holding the open **Primary** trail entry. `view` uses `Document::visibleTo()` (Workstation: own office's documents, never confidential); `changePrimary`/`changeCategory`: Records roles any document, Supervisory endorsed only. Who sees everything: `User::seesAllDocuments()`. |
| `app/Http/Middleware` | `HandleRoles` (`role:`), `CheckWorkingHours`, `TrackUserActivity`, `HandleInertiaRequests` (shares `auth.user`, `auth.canViewAdmApprovals`, `auth.canViewOcmApprovals`, `features.paperless`, lazy `incoming`). |
| `app/Models` | `Document` (`is_delayed`, `is_delinquent`, `stage`, `primaryEntry()`, `holders()`, `scopeHeldBy`, `scopeCopiedTo`, `isPaperless()`), `DocumentTrail` (`stage`, `copy_role`, `STAGE_*`), `Department`, `User` (`certificate_*`), `MailSetting`, `Person`, registries (`DocumentType` + rulebook, `TransactionType`, `DocumentClassification`, `DeliveryMethod`, `PerformanceStandard`), `AuditLog`, `LoginHistory`. Active/inactive = integer `state` (1/0). |

### Roles (string column `users.role`)
`system_administrator`, `records_manager`, `city_administrator`, `city_mayor`, `department_head`, `staff`.
FGD mapping (confirmed 2026-10-22): Supervisory = `city_administrator`, `city_mayor`, `department_head`;
Workstation = `staff`; Records Section = users in the `REC` office.

### Department codes the code depends on
`REC` (Records), `ADM` (City Administrator), `OCM` (Office of the City Mayor). Exactly one active department per code
(`DocumentWorkflow::office()`).

### Custody model
Custody = open trail entries (`released_at IS NULL`). Each open entry has `copy_role` (`primary` or `copy`) and, on
the Primary entry, an optional `stage` (`adm_approval`, `ocm_approval`; null = normal processing). Only the office
holding the open Primary entry acts, and only after it has **received** (`received_by`); copies close when the
Primary ends the document. `documents.department_id` is always the Primary office. Delinquency counts the Primary only.

### Client tracking
Intake (or an Edit that adds/changes the email) queues `SendTrackingCode`. The client enters barcode + access code on
`/login` → Guest tab → `POST /track` (5 wrong tries per IP, then a wait; one message for every miss) → the result is
flashed once to the page: latest status, as-of time, barcode, received time, subject unless confidential. Access
codes are made on the server (`Str::random(6)`, uppercased).

### Frontend
`resources/js/app.ts` boots Inertia; pages resolve from `resources/js/pages/**`. Alias `@` → `resources/js`.
- Pages: `documents/{Index,Show,Edit}` (intake form + list, detail + trail, edit), `transactions/Index` (Office Inbox),
  `adm-approvals/Index`, `ocm-approvals/Index`, `Dashboard`, `dashboard/Report`, `mail-settings/Index`,
  `settings/{Profile,Password,Appearance,Certificate}`, registries (`departments`, `users`, `people`, `document-types`,
  `transaction-types`, `document-classifications`, `delivery-methods`, `performance-standards`), `auth/*`
  (`Login` has the Employee / Guest tabs).
- Shared components: `AppSidebar`/`NavMain` (sections Documents / Approvals / Administration), `OfficePicker`,
  `RoutingModal`, `ApprovalDialog` (ADM + OCM), `DeleteConfirmationModal`, `InputError`; `components/ui/*` generated
  primitives (ESLint-ignored).
- `lib/intakeScan.ts` (OCR + barcode), `lib/linkedDocuments.ts`, `lib/stampPlacement.ts`;
  `composables/useIntakeRulebook.ts`.

## 4. Setup & commands

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # then set DB_* (MySQL), OPENAI_API_KEY, MAIL_*
php artisan migrate
php artisan storage:link
composer dev            # artisan serve + queue:listen + vite
```

**How the user runs it locally (2026-10-30):** `php artisan serve --host=192.168.4.33 --port=8000` and `npm run dev`
separately, which starts **no queue worker** — emails wait in the `jobs` table. Run `php artisan queue:work` as well
(or use `composer dev`). In production the worker must run permanently (e.g. a Windows service / Task Scheduler).

Env vars beyond Laravel defaults (all in `.env.example`): `OPENAI_API_KEY`, `OPENAI_ORGANIZATION`,
`DMS_INTAKE_AI_MODEL` (empty = `gpt-6-luna`), `DMS_INTAKE_AI_REASONING_EFFORT` (empty = `none`),
`DMS_PAPERLESS_ENABLED` (empty = off). `APP_URL` is used for the link in the tracking-code email — it is
`http://192.168.4.33:8000` locally and must be the public address in production.
`DMS_CERTIFICATE_PRIVATE_KEY_PASSWORD` in the local `.env` is no longer read (per-signer certificates replaced it).

`vite.config.ts` sets `hmr.host: 192.168.4.33` (LAN dev; the working tree has the user's uncommitted edit). The
in-app preview `.claude/launch.json` (`dms-local`) serves on 127.0.0.1:8001.

### Check results (run 2026-10-30, check-only modes)

| Check | Command | Result |
| --- | --- | --- |
| Tests | `./vendor/bin/pest` | ✅ 205 passed, 2170 assertions (re-run after the visibility rules) |
| ESLint | `npx eslint .` | ✅ no errors |
| Prettier | `npx prettier --check resources/` | ❌ 20 files need formatting (pre-existing) |
| Type check | `npx vue-tsc --noEmit` | ❌ TS2688: cannot find type definition `./resources/js/types` (tsconfig `compilerOptions.types`) |
| Pint | `./vendor/bin/pint --test` | ❌ 65 files (10 of them only CRLF line endings in the working copy; git normalizes on commit) |
| Build | `npm run build` | ✅ |

⚠️ `npm run lint` = `eslint . --fix` and `npm run format` = `prettier --write`; both modify files. CI
(`.github/workflows/lint.yml`) runs Pint/format/lint in **fix** mode, so it does not fail on style.

## 5. Current status (2026-10-30)

- Branch `main`, in sync with `origin/main` at `69ff0f8`. **Uncommitted (all of 2026-10-30, see §10):** Email
  Settings `.env` password fallback; client tracking (tracking-code email, `/track`, Guest tab, status-change emails
  removed, migration `2026_10_30_000000_drop_client_notified_status_from_documents_table`); documentation refresh;
  dashboard scoped to the own department for Workstation staff; category change limited to Records/Sys
  Admin/Supervisory; Edit/Route buttons shown only where allowed; intake requires a destination; city seal in emails.
  The user's own uncommitted `package-lock.json` and `vite.config.ts` changes are untouched.
- Migrations: all applied locally (`migrate:status`, 2026-10-30). Other environments need everything from
  `2026_10_21_*` onward, in order.
- Fresh-data reset **done** (2026-10-30, by the user). The local database then held 1 test document (C003192158,
  routed through OCM to CBO and Completed by the end of the day) and the `mail_settings` table is empty (the `.env`
  SMTP account is in use).

### Verified by the user in the browser
- 2026-10-30, as reported by the user ("all worked"): receive-first Office Inbox, ADM receive + recorded approval,
  OCM receive + recorded approval → departments, Department Head wet-signature record, AI autofill with real scans,
  notification bell, stale-form protection, clickable linked documents, change Primary office. (The old status-change
  email was also on that list; it has since been replaced.)
- 2026-10-30: tracking-code email received (after the queue was run) and the Guest tab lookup showed the latest
  status for the real document.
- 2026-10-30, checked by Claude Code in the preview (Records Manager): no Edit/Route on a document Records does not
  hold or a completed one; intake with Departments and no office is blocked before saving. The email with the seal
  was previewed only (built in memory, not sent).
- Earlier: intake rulebook form (2026-10-21), dashboard + printable reports (2026-10-23), sidebar (2026-10-26),
  Email Settings page render (2026-10-26/30).

### Built but switched off or unverified
- Paperless route (PNPKI per-signer signing, AI stamp placement): off; real PNPKI `.p12` files never opened under
  OpenSSL 3 and no signed PDF checked in a PDF reader.
- Delete document: button hidden at the user's request; backend unchanged.
- Email Settings save + test email: not exercised with a real account (the `.env` account sends today).
- Dashboard office scope and the Change category control (2026-10-30): seen in the browser as the Records Manager
  ("Showing all offices"; control present) and as CHO staff ("Showing Office of the City Health Officer only").
- Confidential documents hidden from Workstation staff (2026-10-30): tests only; the local database has no
  confidential document to look at. Seen as CHO staff: an empty documents list and 403 on a document CHO never held.

## 6. FGD process (reference)

Source: Google Slides "Document Management Focus Group Discussion" (presentationId
`1re2mVrRkyyvndW1gAm3F6DvMXTyrgjYDKDS0AzLelno`), slides 5–9 (workflows, RBAC) and 13–16 (FGD answers); rulebook in
the Google Sheet "Document Management System Sheets" (`1monsI2DhgNCQoWd9Kuam-KBMM5Es3XJAyfjEuFJ2OfQ`).

**Phases:** 0 rulebook + 24 h delinquency ✅ · 1 confidential + route mode ✅ · 2 stages, intake Direct to, ADM single
approval, per-signer PNPKI, referral slips retired, reset command ✅ · 3 OCM routes to departments ✅ (2026-10-29) ·
4 receiving office: receive-first ✅, Dept Head wet-signature record ✅, Dept Head PNPKI ⏸ (with paperless) ·
5 monitoring: 24 h per Primary ✅, ADM delinquency tagging ❌, delayed-by-category view ❌ · 6 later: client status
lookup ✅ (2026-10-30), office-scoped visibility ✅ (2026-10-30), SMS ❌, spreadsheet reports ❌.

**Decisions (user, 2026-10-21 → 2026-10-30):**
- City Administrator gives a single approval (no referral slip; removed entirely). ADM/OCM route directly to
  departments, never back to Records.
- Paper first, like the old DTS (2026-10-29): office staff record the executives' approvals; the signed scan is
  optional; paperless hidden behind `DMS_PAPERLESS_ENABLED`. When paperless returns: paperless only when the type is
  signed Digital **and** the document is not confidential; signers use their own PNPKI `.p12` (uploaded by the
  signer in Settings → Signing certificate; PIN typed each time, never stored).
- Proxy e-signature replaced by a wet-signature record by any staff of the office (2026-10-29).
- Delinquency: 1440 minutes (24 h) per office, Primary office only; per-office/type calibration in
  `performance_standards` pending CART's Citizen's Charter review. Delayed = end-to-end beyond the category limit
  (Simple 3 / Complex 7 / Highly Technical 20 days, RA 11032).
- Primary office + view-only copies (2026-10-22): Records picks the Primary (AI pre-selected); Records or the
  Supervisory Level may change it; confidential documents get no copies; copies close with the Primary.
- Confidential = Yes on a type means *may be* confidential: Records ticks it per document; confidential documents
  get no AI assist and no upload, and client emails/lookups omit the subject.
- Clients (slide 13; user 2026-10-30): one email (SMS later) with barcode + access code; they see the latest status
  only, on the sign-in page. No email per status change.
- Fresh start of document data (done 2026-10-30); offices, users, registries kept.
- Email Settings stores the SMTP account in the app (exception to the `.env`-only rule, 2026-10-26).
- Dashboard (slide 9; user 2026-10-30): System Administrator, Records (role `records_manager` or anyone in the `REC`
  office) and the Supervisory Level see every office; Workstation staff see their own department on every card and
  report.
- Category (user 2026-10-30): only Records, the System Administrator and the Supervisory Level may change it.
- Visibility (user 2026-10-30): Supervisory = City Administrator, City Mayor, Department Heads. The Supervisory Level
  **sees** every document but **transacts and corrects** (category, Primary office) only documents endorsed to them
  (their office holds the Primary copy; for the City Administrator/Mayor, while it waits at ADM/OCM). Records and the
  System Administrator correct any document. Workstation staff see only documents their office has handled.
- Confidential (user 2026-10-30, "Records only"): only Records, the System Administrator and the Supervisory Level
  see a confidential document's details. The office it is routed to still receives, routes or ends it, from the
  paper copy: its inbox, approval queue, bell and reports show the barcode and "Confidential document" only.

**RBAC (deck slide 9):**
| Capability | Sys Admin | Records Section | Supervisory | Workstation | External client |
| --- | --- | --- | --- | --- | --- |
| User management | ✔ | | | | |
| System settings & registries | ✔ | ✔ | | | |
| Document registration (barcode, scan, AI form) | ✔ | ✔ | | | |
| Dashboard | all offices | all offices | all offices | own department | |
| View transactions | all | all | all | own department | |
| Transact | any document | any document | edit only if endorsed to them | own department | |
| Executive approvals | ✔ | ✔ | sign / approve when endorsed | | |
| Track document status | | | | | ✔ (latest status only) |

**Rulebook answers (sheet tab *Rulebook*, ADM response):** Digital = Communications, Project Proposal (both
Confidential = Yes); Handwritten = the other types except Permits (blank, "to be consulted"). Category: Simple = AOC,
AIR/RIS, BAC Resolution, OBR, Permits, PO, PR; Complex = Payroll; Highly Technical = IPW; varies = Communications,
Disbursement Voucher, Project Proposal. Seeded by `DocumentTypeRulebookSeeder`, editable in Document Types.

**Still open:** SMS provider and cost; Permits signature type.

## 7. Known issues, risks & technical debt

**Security**
1. Document attachments are stored on the `public` disk (`storage/app/public/documents`) and are reachable by URL
   without signing in.
2. ~~Every active user could see every active document, including confidential ones.~~ **Resolved 2026-10-30:**
   `Document::scopeVisibleTo()` + `hideConfidentialDetailsFrom()` (see the change log). The signed-scan preview
   (`documents.signed-pdf`) authorizes with `view`, so it follows the same rule.
3. `/register` and `Register.vue` still exist, so anyone can self-register by URL.
4. `HandleInertiaRequests` shares the full `User` model to the browser (password/2FA/certificate path hidden;
   other columns such as `signature_path` exposed — last reviewed 2026-10-13).
5. `.gitignore` contains merge-conflict markers; `/public/certificates` is not ignored (the folder was deleted).
6. Public `/track` is limited per IP: offices behind one shared public IP share the 5-tries limit.

**Operations**
7. Emails need a running queue worker; none runs with `artisan serve` + `npm run dev` alone.
8. `APP_URL` must be the public address before clients get emails outside the LAN.
9. `.env.example` defaults to SQLite and `MAIL_MAILER=log`, while local dev uses MySQL + SMTP.

**Maintainability**
10. `DocumentController::create()` renders a non-existent `documents/Create` page and has no route (dead code).
11. `app/Models/User.php` imports `Laravel\Fortify\TwoFactorAuthenticatable` but Fortify is not installed.
12. `routes/web.php` declares `dashboard` twice (closure and `DashboardController`); the later one wins.
13. `findStampPlacement` calls OpenAI from the controller; `gpt-4o-mini` hard-coded (4 places).
14. `DocumentAnalysisService` is unused.
15. Migrations add → remove → re-add `signed_file_path` on `document_trails`; trail columns used only by the retired
    referral slips (`required_actions`, …) remain for history.
16. `vue-tsc` broken via tsconfig `types`; Pint/Prettier debt (§4); no `typecheck` npm script.
17. `README.md` still begins with the Laravel starter-kit text; `composer.json` name is `laravel/vue-starter-kit`.
18. Vite warns about a >500 kB chunk (OCR/PDF libs).
19. **Mixed timestamps:** app timezone is UTC, so `created_at`/`updated_at` are real UTC while workflow timestamps
    (`received_at`, `released_at`, `due_date`, `completed_at`) are Manila wall-clock stored as if UTC. Frontends and SLA
    math treat them differently (`formatUtcDateStr`, `Document::is_delinquent`, `DashboardReportService::totalDays`).
    Dashboard month filters use `whereMonth('created_at')` in UTC, so a document registered before 8 AM Manila on
    the 1st counts in the previous month.

## 8. Next steps (suggested)

1. Commit the uncommitted 2026-10-30 work (§5) — the user said "not yet"; needs their OK. Before that, send one real
   tracking-code email to see the seal in Gmail.
2. Production readiness: permanent queue worker, public `APP_URL`, a real sender name in Email Settings (local
   `MAIL_FROM_NAME` is "Laravel"), run all migrations, `ziggy:generate` + `npm run build`.
3. Security: attachments on the private disk behind an authorized download (#1); remove `/register` (#3).
4. FGD Phase 5: ADM delinquency tagging and the delayed-by-category view.
5. SMS: choose a provider (e.g. Semaphore or a Globe/Smart gateway) and send the same barcode + access code.
6. Small cleanup change with tests: #10–#14, `.gitignore` (#5), tsconfig `types` so `vue-tsc` runs (#16).
7. Decide the timestamp convention (#19) before further date/SLA work.
8. Later: spreadsheet export of reports; paperless switch-on (test real PNPKI `.p12` files under OpenSSL 3 first).

## 9. Decisions & assumptions

- Decision (existing code): optimistic concurrency via `workflow_version` + pessimistic row locks inside `mutate()`.
- Decision (existing code): executive approvals keyed by department code, not IDs.
- Decision: trail history is never rewritten; `dms:reset-documents` is the only code that deletes it (user-approved
  fresh start).
- Decision (user, 2026-10-30): this file is the single source of project and feature status; `status.md` is
  outdated and disregarded.
- Assumption: local environment is XAMPP on Windows (path `C:\xampp\htdocs\dms`), served on the LAN at
  192.168.4.33.

## 10. Change log

Newest first. Everything up to 2026-10-29 is committed and pushed (latest `69ff0f8`); the 2026-10-30 entries are
uncommitted. Older "not checked in the browser" notes are superseded by §5 "Verified by the user in the browser".

### 2026-10-30 — Visibility by role; confidential details hidden; Supervisory corrects endorsed only (Claude Code, uncommitted)
- **Why (user decisions):** the Supervisory Level (City Administrator, City Mayor, Department Heads) sees all
  documents but transacts only those endorsed to them; confidential documents: "Records only"; changing the category
  or Primary office counts as transacting.
- `User::SEES_ALL_DOCUMENTS_ROLES` + `seesAllDocuments()` (System Administrator, Records Manager, Supervisory, anyone
  in `REC`); `DashboardReportService::seesAllDepartments()` now delegates to it (its constant was removed).
- `Document::scopeVisibleTo()`: everyone else sees only active, non-confidential documents with a trail entry at their
  office (documents list, document page via policy `view`, barcode search, linked documents, copies list).
  `Document::CONFIDENTIAL_FIELDS`, `detailsHiddenFrom()`, `hideConfidentialDetailsFrom()` (serialization only:
  `makeHidden` + `details_hidden`).
- Places an office must act on a confidential document no longer use `visibleTo()` and hide the details instead:
  Office Inbox (+ bell focus document), ADM and OCM approval queues; the bell shows "Confidential document"; Workstation
  dashboard reports blank the sender fields and show "Confidential document". Pages: `transactions/Index.vue`
  (label, no View link, Process dialog text), `adm-approvals/Index.vue`, `ocm-approvals/Index.vue`,
  `dashboard/Report.vue` (barcode without a link).
- `DocumentPolicy::corrects()`: Records roles any document; Supervisory only when `owns()`; used by `changePrimary` and
  `changeCategory`.
- Tests: new `ConfidentialVisibilityTest` (9 cases); `DocumentCategoryTest` (endorsed-only case; the fixture now has
  its Primary trail entry); `PrimaryCopyRoutingTest` (copy office's head refused, Primary office's head allowed);
  `WorkflowReliabilityTest` (Workstation staff see a document only after their office handles it). Pest 205 passed
  (2170 assertions); Pint: no new violations; ESLint ✅; build ✅. Browser (CHO staff): dashboard "Showing Office of
  the City Health Officer only", empty documents list, 403 on C003192158.

### 2026-10-30 — Emails: city seal instead of the Laravel logo (Claude Code, uncommitted)
- **Why (user):** the email header showed the Laravel logo (Laravel draws it when `APP_NAME` is "Laravel").
- Overrode two framework mail components (others still come from the framework): `resources/views/vendor/mail/html/
  header.blade.php` (seal image when a `logo` is given, then "City Government of San Fernando"; never the framework
  logo) and `.../html/message.blade.php` (passes `logo` to the header; footer "© {year} City Government of San
  Fernando, La Union · Document Management System"). Every email, password reset included, now has the city header
  and footer.
- `mail/document-tracking-code.blade.php` embeds `public/images/san_fernando_logo.png` **inline** (`$message->embed`,
  a `cid:` image), because a link to the DMS server (`APP_URL`, LAN-only) would show broken in Gmail. The cid is
  computed once into a variable: Blade evaluates an anonymous component's attribute expression twice, which
  attached the seal twice. Previews/tests without `$message` show the text header only.
- Checks: Pest 195 passed (2017 assertions; new `ClientTrackingTest` case sends through a real in-memory mailer:
  exactly one inline PNG, referenced by the HTML, no `laravel.com`, no `cid:` in the text part); Pint ✅. The email
  for C003192158 was built in memory (not sent) and shared with the user as an HTML preview. **No real email sent**
  with the new header yet.

### 2026-10-30 — Intake requires a destination (Claude Code, uncommitted)
- **Why (user decision):** "It should require where to go to, not stale." Direct to = Departments with no office
  chosen used to save the document in the registering user's own office (Records, or e.g. CICTO for a System
  Administrator) with no inbox entry and no ping.
- `SaveDocumentRequest::after()` (create only): refuses no `department_id` and no `routed_to` ("Choose where the
  document goes: the City Administrator, the City Mayor, or at least one department.", on `routed_to`) and a
  `department_id` equal to the user's own office ("Choose an office other than your own."). Edit is unchanged.
- `documents/Index.vue`: Save with Departments and no office shows the same message under Send to (and a toast)
  without posting; "No departments chosen" turns red; the error clears once an office is picked.
- Documents can still reach Records when an office routes them back; Records' Route button handles those.
- Tests: `WorkflowReliabilityTest` "creation without a destination retains records custody" became a refusal test;
  new `IntakeToDepartmentsTest` case; `RecordsIntakeTest` and `ClientTrackingTest` payloads now carry a destination,
  and their Edit cases first return the document to Records (as an office routing it back would). Pest 194 passed
  (2009 assertions); Pint ✅ on changed files (except a pre-existing import-order issue in
  `WorkflowReliabilityTest.php`); ESLint ✅; build ✅. Browser (Records Manager): Departments + no office + Save shows
  the message and sends no request.

### 2026-10-30 — Documents list: Route hidden on completed documents (Claude Code, uncommitted)
- **Bug (user report, screenshot):** completed C003192158 still offered Route. Every trail entry was closed, so
  `holders` was empty and the page fell back to `department_id` (CBO, the office that ended it) as the holder;
  policy `owns()` has the same fallback. Routing would have been refused (409), but the button was misleading.
- `DocumentController::index` now also sends `can_route` (policy `dispatch` + not Completed); `documents/Index.vue`
  shows Route only when `can_route` (the client-side `holdsCopy` check was removed) and no longer shows "With …"
  under a completed document.
- Checks: Pest 193 passed (2006 assertions; `DocumentListActionsTest` now 5 cases, incl. the completed-with-no-open-
  entry case); ESLint ✅; build ✅; Pint ✅. Browser (Records Manager): C003192158 shows "Completed" with no Route or Edit.

### 2026-10-30 — Documents list: Edit button only where editing is allowed (Claude Code, uncommitted)
- **Why (user):** the pencil showed on every row, and clicking it on a document Records no longer holds (or as a
  non-Records user, or on a completed document) gave a 403/409 page.
- `DocumentController::index` adds `can_edit` per row (policy `update` + not Completed — the same gates as `edit()`;
  6 rows per page, so the per-row check is cheap). `documents/Index.vue` shows the Edit link only when `can_edit`.
- Checks: Pest 190 passed (1961 assertions; new `DocumentListActionsTest`, 2 cases); ESLint ✅; build ✅; Pint ✅ on the
  new test. Browser (`dms-local`, Records Manager): C003192158 (not held by Records) shows no Edit button.

### 2026-10-30 — Dashboard by department; category change by role (Claude Code, uncommitted)
- **Why (user, FGD slide 9):** Workstation staff see their own department only; only Records, the System
  Administrator and the Supervisory Level may change a document's category.
- **Dashboard:** `DashboardReportService::ALL_DEPARTMENT_ROLES` now follows slide 9 (adds `city_mayor` and
  `department_head`, which were office-scoped before), and anyone in the `REC` office counts as Records
  (`seesAllDepartments`). New `scopeToUser()` + `Document::scopeHandledBy()` (documents the office ever held as
  Primary) scope the monthly summary cards, the pipeline, average processing times and the pipeline reports (title
  gets "(Your Office)"); the alert cards keep `heldBy` (held now). `Dashboard.vue` shows "Showing {office} only" or
  "Showing all offices" (new `scope` prop).
- **Category:** policy `changeCategory` (Records roles + Supervisory, active and not completed), `ChangeCategoryRequest`
  (`transaction_type_id` + `workflow_version`), `DocumentService::changeCategory()` (inside `mutate()`, refuses the
  same category, recomputes `due_date` from `created_at` like Edit, writes an `AuditLog` row `category_changed`),
  route `documents.category` (`POST /documents/{document}/category`, `working_hours`). `documents/Show.vue`: a
  "Change category" select + button under Transaction Type for allowed roles. Edit stays Records-only as before.
- Checks: Pest 188 passed (1934 assertions; new dashboard scope test, new `DocumentCategoryTest` 9 cases); ESLint ✅;
  build ✅; Ziggy regenerated; Pint: no new violations (touched files keep their pre-existing debt; the policy file
  passes once CRLF is ignored); Prettier: `Dashboard.vue` and `Show.vue` already failed at HEAD.
  **Not checked in the browser:** the preview browser could not reach the Vite dev server at 192.168.4.33:5173
  (connection timed out); the user's dev server was left as is.

### 2026-10-30 — Documentation refresh (Claude Code, uncommitted)
- Sections 1–9 rewritten to match the code (referral slips, single certificate and status-change emails removed from
  the description; FGD decisions condensed into §6; known issues re-checked against the code; checks re-run, see §4).
  Change-log labels corrected: entries up to 2026-10-29 were committed, not "uncommitted".

### 2026-10-30 — Client tracking: one barcode + access code email, public lookup on the sign-in page (Claude Code, uncommitted)
- **Why (user decision 2026-10-30, FGD slide 13):** clients get their barcode + access code once (email now, SMS
  later) and look up the latest status themselves on the DMS sign-in page. **Status-change emails were removed.**
- Removed: `NotifyClientOfStatus`, `ClientStatusUpdate` + `mail/client-status-update` view, `ClientStatusEmailTest`,
  `DocumentWorkflow::notifyClient()` (and its call in `mutate()`). Migration
  `2026_10_30_000000_drop_client_notified_status_from_documents_table` (**run locally**).
- New `SendTrackingCode` job (queued, 3 tries, logs "Tracking code email failed.") + mailable `DocumentTrackingCode`
  (`mail/document-tracking-code`: barcode, access code, subject unless confidential, received time, "Track my
  document" button → `/login?tab=track`). Queued by `DocumentService` after registration and after an Edit that
  adds or changes the client email; a queueing error is logged and never fails the save.
- New public route `POST /track` (`track`, guest group in `routes/auth.php`; exception recorded in CLAUDE.md),
  `TrackDocumentController`, `TrackDocumentRequest` (5 wrong tries per IP, then a wait message),
  `ClientStatusService::track()` (active document, barcode + access code compared case-insensitively and in
  constant time; returns barcode, subject unless confidential, received time, latest status, as-of time — never the
  trail). Every miss gets one message: "No document matches that barcode number and access code." The result is
  flashed once to the sign-in page (`initialTab`, `tracking` props).
- `auth/Login.vue` Guest tab now works: barcode + access code form with field errors, result card ("Latest status",
  as of, barcode, received, subject), "Track another document". Unused `TextLink` import removed.
- `documents/Index.vue`: after a save the next access code comes from the server (`Str::random`), not
  `Math.random()` in the browser.
- **Email link host:** `route('login')` in the queued email uses `APP_URL` (`http://192.168.4.33:8000` locally);
  set it to the real public address in production, or clients get an unreachable link.
- Checks: Pest 179 passed (1776 assertions; new `ClientTrackingTest`, 9 cases); Pint ✅; ESLint ✅; Prettier ✅ on
  `Login.vue` (`documents/Index.vue` already failed at HEAD); Ziggy regenerated; build ✅. Browser (`dms-local`):
  `/login?tab=track` opens the Guest tab; a made-up barcode/code shows the no-match message. The console held 5 Axios "Network Error"
  entries (ERR_CONNECTION_REFUSED) from the tab's earlier signed-in pages; not investigated, not shown to be caused by
  this change.
- **First real email (same day):** the user registered a document with their own email and got nothing: they run
  `php artisan serve` (192.168.4.33:8000) + `npm run dev` separately, so **no queue worker** was running and the
  job waited in `jobs`. Ran `php artisan queue:work --stop-when-empty` once: `SendTrackingCode` DONE in 4 s over
  the `.env` SMTP account (no `mail_settings` row). Also deleted 4 stale `NotifyClientOfStatus` jobs (ids 1–4, class
  removed). Keep `php artisan queue:work` running next to `artisan serve`.
- **Verified by the user (same day):** the email arrived with barcode + access code, and the Guest tab lookup showed
  the latest status of the real document.

### 2026-10-30 — Email Settings: the .env password fills a blank first save (Claude Code, uncommitted)
- **Why (user request):** "autofill the password from .env". The password is still **never sent to the browser**
  (CLAUDE.md Security), so the autofill happens on the server instead.
- `MailSettingsService::envPassword()` (MAIL_PASSWORD via `config('mail.mailers.smtp.password')`, null when empty);
  `save()`: a blank password on the **first** save takes the .env one; later blank saves keep the saved one.
  `MailSettingRequest`: password required only when no row exists **and** .env has none. `MailSettingController`
  passes `defaults.has_password` (a boolean, never the value).
- `mail-settings/Index.vue`: before the first save, the field reads "From the .env file — leave blank to use it" and
  is not required.
- Checks: Pest 176 passed (1732 assertions; new MailSettingsTest case: blank first save stores the .env password,
  page props and HTML never contain it, later .env changes don't overwrite it); Pint ✅; ESLint ✅; Prettier ✅; build ✅.
  Browser (seeded System Administrator, `dms-local` on :8001): placeholder shown, field not required, page props hold
  only `has_password: true`. **Not saved** (it would store the real account); the sender name still shows "Laravel".

### 2026-10-29 — Linked documents open their document (Claude Code)
- `DocumentController::show` passes `linkedDocumentIds` (barcode => id) for linked barcodes of active documents the
  user may see (`visibleTo`); `documents/Show.vue` renders each as a link to that document (white chip, grey outline,
  blue text, underline on hover, focus ring). A barcode with no registered document stays plain white text with a
  tooltip. Green fill removed.
- Checks: Pest 175 passed (1717 assertions; new `LinkedDocumentsTest`); ESLint ✅; build ✅; Pint ✅. Not checked
  in the browser.

### 2026-10-29 — Documents list: aligned actions, Delete hidden (Claude Code)
- `documents/Index.vue`: the "With CHO" / "Pending City … Approval" text moved from the Actions column to under the
  status, so it no longer pushes the buttons around. Actions are same-size 32 px buttons, centered: Route (only when
  Records may route it), then Edit. (An empty placeholder slot was tried first; it left Edit off-centre.)
- **Delete hidden for everyone (user request):** the button, `DeleteConfirmationModal` use and its state were removed
  from the page. The backend (`documents.destroy`, `DocumentService::deactivate`, policy `delete`) is unchanged, so
  it can be brought back by restoring the button. No other document page had a delete button.
- Checks: ESLint ✅; build ✅. Not checked in the browser.

### 2026-10-29 — Document trail: the Primary office's action is the latest row (Claude Code)
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

### 2026-10-29 — Office Inbox: Receive first, act later (Claude Code)
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

### 2026-10-29 — Hybrid paper route (old DTS style); paperless switched off; OCM routes to departments (Claude Code)
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

### 2026-10-28 — Linked documents are comma-separated (Claude Code)
- New `resources/js/lib/linkedDocuments.ts` (`barcodeBeingTyped`, `withChosenBarcode`, `linkedDocumentsText`), used by
  `documents/Index.vue` and `documents/Edit.vue`: picking a barcode suggestion gives "C003192158, C003192314, ";
  placeholders updated. The server already splits on commas and spaces (`DocumentService::save`), so no backend change.
- **Fixed on Edit:** `linked_documents` arrives as an array (model cast) and the page called `.trim()` on it; it is now
  shown as comma-separated text.
- Checks: ESLint ✅; Prettier ✅ on the new file; build ✅. Browser (intake page): two suggestions picked in a row gave
  `C003192158, C003192314, `. Edit page not opened in the browser.

### 2026-10-28 — Intake form: numbered steps, confidential and upload first (Claude Code)
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

### 2026-10-26 — Email Settings page (Claude Code)
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

### 2026-10-26 — Client status email; cleaner sidebar (Claude Code)
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

### 2026-10-23 — Dashboard details from the old DTS (Claude Code)
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

### 2026-10-23 — Intake routing edge cases (Claude Code)
- `DocumentIntakeAnalyzer::validated()`: `department_id` is discarded unless it is ADM or OCM; routing suggestions
  never include Records, and include OCM only when the document goes to the City Administrator (ADM may forward
  to the Mayor). So the Primary picked by the server is always an office the intake form can route to.
- `documents/Index.vue`: the autofilled Primary is chosen from the filtered offices (it could be OCM/Records and
  fall outside `routed_to`), and a confidential document keeps that Primary instead of the first suggestion.
- Checks: Pest 142 passed (1268 assertions; 3 new tests in `DocumentAnalysisTest`); Pint ✅; ESLint ✅.
  **Not tested in the browser** (needs a real scan and AI call).

### 2026-10-23 — FGD Phase 2e: fresh-data reset command (Claude Code; committed; run by the user 2026-10-30)
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

### 2026-10-23 — FGD Phase 2d: referral slips retired (Claude Code)
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

### 2026-10-23 — FGD Phase 2c-2: per-signer PNPKI certificates (Claude Code)
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

### 2026-10-23 — FGD Phase 2c: ADM single approval (Claude Code)
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

### 2026-10-23 — FGD Phase 2b: intake "Direct to" Departments (Claude Code)
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

### 2026-10-23 — FGD Phase 2a: explicit stage on the open Primary entry (Claude Code)
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

### 2026-10-23 — Intake page: leaner layout in the old DTS style (Claude Code)
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

### 2026-10-23 — People Directory: complete sender names hidden under signatures (Claude Code)
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

### 2026-10-23 — Intake AI: referral-slip signer, split surnames, subject names the invitee (Claude Code)
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

### 2026-10-22 — Intake AI autofill: more accurate, half the tokens (Claude Code)
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
