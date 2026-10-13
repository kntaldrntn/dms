# Project health and improvement tracker - 2026-10-09

## Legend

- 🟢 Working and verified
- 🟡 Working, but needs manual confirmation or is a future improvement
- 🔴 Known issue that needs action before relying on the feature

## Updates since the previous tracker review

This section supersedes older overlapping notes below.

- [Done] OCM approval now provides an uploaded-PDF preview before **Digitally Stamp & Return**.
- [Done] Fixed the OCM PDF-preview canvas error caused by a local variable hiding the browser `document` object.
- [Done] OCM stamp placement now uses the AI result when available and a conservative browser-side blank-area fallback when it is not.
- [Done] The approved OCM PDF opens as an authorized inline preview from Document Details rather than forcing a download.
- [Done] The visible OCM stamp now contains a transparent black signature on the left and the certificate holder, City Mayor role, and signing time in plain black text on the right.
- [Done] The configured certificate is issued by DICT Government Authentication CA - G2, has Digital Signature key usage, and is valid until May 15, 2027.
- [Done] Dashboard delinquency, To-Do, and Due Tomorrow metrics and their drill-down now cover all active departments, while evaluating each document against its current department's SLA.
- [Done] Fixed the delinquency elapsed-time calculation so overdue documents are counted correctly with the installed Carbon version.
- [Done] Verification after these updates: dashboard tests (4), workflow reliability tests (27), PHP syntax validation, production frontend build, and whitespace validation pass.

## Current platform and code health

### Automated checks

- 🟢 PHP syntax validation passes for controllers, models, services, requests, policies, and migrations.
- 🟢 Laravel route registration passes with 62 active routes.
- 🟢 Automated tests pass; the dedicated workflow suite includes OCM-to-Mayor custody and queue coverage.
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
- 🟢 OCM routing: documents addressed to the active department coded `OCM` are transferred into City Mayor custody, recorded with `department_id = OCM`, and marked `requires_signature_from = city_mayor`. The original custody release and OCM receipt are retained in the trail.
- 🟢 The City Mayor uses the dedicated **OCM Approvals** queue, not the City Administrator Referral Slips page. Referral Slips remain restricted to City Administrator and System Administrator roles.
- 🟡 OCM PDF approval: the one-step **Digitally Stamp & Return** action renders up to five PDF page previews, asks the existing OpenAI vision integration to locate a blank signature area, validates the normalized coordinates server-side, visibly stamps the supplied signature, cryptographically signs a separate PDF, and returns the document directly to Records. The original upload remains unchanged and the signed copy is stored privately for authorized download from Document Details.
- 🟡 Before live signing, configure `DMS_CERTIFICATE_PRIVATE_KEY_PASSWORD` in the local environment. Certificate material is read from `storage/app/certificates/`; duplicate private-key and certificate copies under `public/certificates/` must be removed before deployment.
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

### OCM PDF approval and certificate signing

- 🟢 Added private `signed_file_path` tracking on the OCM approval trail, plus an authorized download endpoint for the resulting approved PDF.
- 🟢 Added FPDI/TCPDF dependencies to stamp the original uploaded PDF without replacing it and apply the certificate-backed PDF signature.
- 🟡 Manual browser test still required: use a real PDF with a clearly blank signature area, sign in as the City Mayor, complete **Digitally Stamp & Return**, and verify both visual placement and certificate status in a PDF reader.
- 🔴 Do not deploy while `private.key` or the `.p12` certificate bundle remains in `public/certificates/`; those duplicate files are web-accessible and must be removed after confirming the protected storage copies are available.

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
