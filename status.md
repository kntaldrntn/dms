# Project health and improvement tracker - 2026-10-05

## Legend

- 🟢 Working and verified
- 🟡 Working, but needs manual confirmation or is a future improvement
- 🔴 Known issue that needs action before relying on the feature

## Current platform and code health

### Automated checks

- 🟢 PHP syntax validation passes for controllers, models, services, requests, policies, and migrations.
- 🟢 Laravel route registration passes with 62 active routes.
- 🟢 Automated tests pass: **58 tests, 264 assertions**.
- 🟢 ESLint passes with no errors.
- 🟢 Production frontend build completes successfully.
- 🟢 `git diff --check` passes with no whitespace errors.

### Database and test setup

- 🟢 Added and applied the missing two-factor user columns migration. The user factory and database schema now agree.
- 🟢 User factory defaults now use a valid role, active state, and no invalid department foreign key.
- 🟢 The home-route test now matches the intended login redirect.

### Routes and controllers

- 🟢 Removed the two routes that referenced non-existent document controller methods: `receiveDocument` and `completeDocument`.
- 🟢 Admin resource routes now expose only the controller actions that exist.
- 🟡 The removed document endpoints should only be restored if the UI or another integration needs distinct "receive" or "complete" actions. The current office-processing endpoint already covers routing and completion.

## Document workflow

### Dashboard document drill-down

- 🟢 Added: every Simple, Complex, and Highly Technical dashboard row opens a DTS-style document report on the Dashboard itself, filtered to the selected month and transaction category.
- 🟢 The dashboard report includes clickable barcodes that open the corresponding document detail and trail view directly.

### Document registration, update, dispatch, referral, and processing

- 🟢 These flows now use request validation, authorization, and transactional services.
- 🟢 Failed uploads and failed workflow writes roll back instead of leaving partial document trails or orphaned files.
- 🟢 Active destination offices are rechecked during the transaction.
- 🟢 Referral and Records office lookups use `ADM` and `REC` department codes instead of hard-coded database IDs.

### Concurrent edits and stale forms

- 🟢 Existing-document changes use `workflow_version`; an older browser tab cannot overwrite a newer update.
- 🟢 Edit, deactivate, dispatch, referral, and transaction UI actions submit the current version.
- 🟡 Manual browser testing is still needed to confirm users understand the stale-form message and refresh flow.

### Visibility and permissions

- 🟢 Document policy registration is explicit.
- 🟢 Listing, detail, barcode search, and mutation permissions follow the same document visibility rules.
- 🟡 Confirm the actual department codes in production are exactly `REC` for Records and `ADM` for City Administrator. The referral workflow depends on them.

## Manual testing to do

- 🟢 Confirmed manually: document routing reaches the intended offices, including onward routing and transaction processing.
- 🟢 Confirmed manually: role-based document access works as intended throughout the tested workflow.
- 🟢 Confirmed manually: the City Administrator's uploaded signature is embedded and displays correctly on the Referral/Action Slip.
- 🟡 Open one document in two browser tabs; save from one tab, then submit the old tab and confirm it is rejected as stale.

## Future improvements

### Integrating embedded uploaded signature in an uploaded PDF file

- 🟡 Deferred. Uploaded-document PDF stamping, its storage field, download endpoint, and FPDI/FPDF dependencies have been removed.
- 🟢 Current scope: the browser-print Referral/Action Slip displays the approving City Administrator's uploaded signature directly above the fixed **ATTY. GILBERT L. CALOZA** signatory block whenever that user's signature is on file.
- 🟢 Manually verified: the uploaded signature is embedded and visible in the generated Referral/Action Slip.

### Enhancing the City Administrator Referral/Action Slip modal

- 🟢 Added a multi-select **Required Action** section: For Approval, For Signature, For Information/Reference, For Appropriate Action, For Review/Evaluation, Let's Discuss/See Me, Please Attend, Please Handle, For Dissemination, Return Document/s to Me, For Comment/Recommendation, and Others.
- 🟢 Added an optional **Concerned Personnel** field and a required custom instruction when Others is selected.
- 🟢 Selected actions, custom instruction, and concerned personnel are saved on the approval trail and shown in document trail details after approval and dispatch.
- 🟢 The modal includes selected-action preview and requires at least one target department plus one required action.
- 🟢 Added a print-optimized Referral/Action Slip page with background summary, selected departments, selected actions, concerned personnel, remarks, signer, and the City of San Fernando logo.
- 🟢 Updated: approval no longer automatically opens the printable slip. A **View Referral/Action Slip PDF** button appears in Document Index for the City Administrator, System Administrator, and the Records Manager currently holding the approved document.
- 🟢 The Document Index slip button now opens an inline PDF preview. Users can inspect the generated referral first, then manually use their PDF viewer's print or download controls.
- 🟢 The printed signature block is fixed to **ATTY. GILBERT L. CALOZA, City Administrator** and automatically displays the approving City Administrator's uploaded signature above the name when it is on file.
- 🟢 Print layout refinement: removed the outer form border, centered the government header and City Administrator signature block, and removed the document number from the print title.
- 🟢 Print header alignment: positioned the city logo independently so it no longer shifts the centered government/office text to the right.
- 🟢 Fixed city-logo display: the print template now uses `/images/san_fernando_logo.png`, which matches the `php artisan serve` public web root. The previous generated `/public/images/...` URL returned 404.
- 🟢 The PDF preview no longer invokes the browser print dialog automatically, so browser-generated headers and footers are not added to the slip.
- 🟢 City logo pending: place `san_fernando_logo.png` in `public/images/san_fernando_logo.png`. The printable slip automatically displays it when present.
- 🟡 Future phase: use the official logo and verify paper spacing/content against a printed government-form sample.

### Document receive and complete endpoints

- 🟡 Not required by the current UI. Reintroduce only if external scanners, mobile clients, or a separate receiving screen needs them.

### City Administrator review queue at document intake

- 🟢 Implemented: when intake selects the department whose code is `ADM`, the document remains in Records custody instead of being directly dispatched to the City Administrator office.
- 🟢 The document is marked `requires_signature_from = city_administrator`, which makes it visible in the City Administrator referral-review queue.
- 🟢 Only the `city_administrator` role can approve the referral through this review path; approval then returns the document to Records for final dispatch.
- 🟢 Records cannot open the routing modal or dispatch a document while City Administrator review is pending; the server also rejects a bypass attempt.
- 🟢 Manual test: register a document with City Administrator selected, confirm it remains in Records, then sign in as City Administrator and confirm it appears in Referral Slips for approval.
- 🟡 Future decision: replace the current "select ADM department" trigger with a clearer explicit "requires City Administrator approval" field if staff find the current intake selection confusing.

### Frontend performance

- 🟡 Vite reports a JavaScript chunk larger than 500 kB, likely because the document page includes OCR/PDF libraries. Consider lazy-loading the OCR feature when performance optimization becomes a priority.

### Audit and operational review

- 🟡 Add a short role-by-role UAT checklist and have Records, City Administrator, and a destination department verify the process with real sample documents.
