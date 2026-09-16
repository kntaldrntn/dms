# Laravel + Vue Starter Kit

## Introduction

Our Vue starter kit provides a robust, modern starting point for building Laravel applications with a Vue frontend using [Inertia](https://inertiajs.com).

Inertia allows you to build modern, single-page Vue applications using classic server-side routing and controllers. This lets you enjoy the frontend power of Vue combined with the incredible backend productivity of Laravel and lightning-fast Vite compilation.

This Vue starter kit utilizes Vue 3 and the Composition API, TypeScript, Tailwind, and the [shadcn-vue](https://www.shadcn-vue.com) component library.

## Official Documentation

Documentation for all Laravel starter kits can be found on the [Laravel website](https://laravel.com/docs/starter-kits).

## Contributing

Thank you for considering contributing to our starter kit! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## License

The Laravel + Vue starter kit is open-sourced software licensed under the MIT license.

# DMS Workflow Documentation

## Overview

This project is a Document Management System (DMS) designed to track incoming documents, assign them to offices, record actions and remarks, and route documents to responsible departments.

## Current Workflow

1. Assign a barcode to the new document
   - A user creates a new document record in the New Document module.
   - The barcode is entered or generated for the document.
   - Required metadata such as source, subject matter, document type, transaction type, department, and delivery method are filled in.

2. Scan the 1st page and capture a screenshot
   - The incoming document is scanned or photographed.
   - A screen capture or image is created for assistive processing.

3. Paste the screen capture into the AI analysis step
   - The image and OCR/extracted text are processed using AI assistance.
   - The system can summarize the content in a few sentences and identify the likely source.
   - The subject matter and source are then used to complete the document entry.

4. Enter the summarized subject matter and source into the New Document module
   - The user completes the remaining fields in the system.
   - The document is saved with its barcode, source details, subject matter, office assignment, and other metadata.

5. Route the document to ADM for City Administrator action and remarks
   - After registration, the document is routed to the appropriate office.
   - In the current workflow, this includes routing to the ADM / City Administrator office for review and action.

6. ADM DTS liaison receives the document
   - The assigned office receives the document in the inbox.
   - The liaison monitors documents assigned to the department and processes them.

7. The City Administrator makes action and remarks
   - The authorized office head reviews the document and decides on the required action.
   - Action and remarks are recorded as part of the document processing.

8. ADM DTS liaison enters the action and remarks, then releases to Records Office
   - The liaison records the decision made by the City Administrator.
   - The document is then released or transferred to the Records Office for archival and final processing.

9. Records Office receives the document and scans the full document
   - The Records Office receives the transferred document.
   - The full document is scanned and attached to the DTS record or digital record.

10. Release it to concerned departments using the Transaction Module
    - Once the document has been archived or processed, it is routed to the relevant departmental office using the Transaction Module.
    - Each department receives the document in its inbox for action or completion.

## Step-by-Step Workflow (AI-Assisted Integration)

1. Receive the document and assign a barcode
   - The document is received by the intake staff.
   - A barcode is assigned or scanned to uniquely identify the document.
   - This barcode becomes the main tracking reference throughout the workflow.

2. Scan the first page and capture the image
   - The first page of the document is scanned or photographed.
   - The captured image is uploaded into the system for AI assistance.

3. Run AI document intake
   - The system extracts the OCR text and image content.
   - The AI identifies the possible source, subject, department, document type, transaction type, and classification.
   - The AI may also suggest routing to the appropriate office.

4. Review and validate AI output
   - The staff reviews the AI-generated fields.
   - Any incorrect OCR result, source name, routing, or subject matter is corrected.
   - This is the critical human validation step before final registration.

5. Save the document in the New Document module
   - Once validated, the user saves the document.
   - The full record is created in the system with barcode, source, subject matter, office assignment, and document metadata.

6. Route the document to ADM for City Administrator action
   - The document is routed to the ADM / City Administrator office for review.
   - The routing is logged in the document trail.

7. ADM DTS liaison receives the document
   - The receiving office sees the document in the department inbox.
   - The liaison verifies the document and prepares it for action.

8. City Administrator reviews and provides action and remarks
   - The City Administrator evaluates the document.
   - The office head issues the final action and remarks.

9. ADM DTS liaison records the action and releases to Records Office
   - The liaison enters the City Administrator’s action and remarks in the system.
   - The document is then forwarded to the Records Office.

10. Records Office receives the document and scans the full document
    - The Records Office receives the document in its inbox.
    - The full document is scanned and attached to the DTS record or digital archive.

11. Release to concerned departments through the Transaction Module
    - After recording and archival, the document is routed to the responsible office or department.
    - The receiving department confirms the document in the transaction/inbox module.
    - The document is marked as completed or rerouted as needed.

## Integrated Flow Summary

Receive Document → Assign Barcode → Scan First Page → AI Intake → Validate Data → Save Document → Route to ADM → Receive by Liaison → City Administrator Action → Liaison Logs Action → Send to Records → Scan and Archive → Release to Concerned Department

## System Components Used in the Current Workflow

- `DocumentController::store()` — creates and saves the new document
- `DocumentController::analyze()` — AI-assisted intake and extraction
- `DocumentController::routeDocument()` — routes the document to the next office
- `TransactionController::index()` — displays department inbox documents
- `TransactionController::process()` — handles action, completion, or rerouting
- `DocumentTrail` — records all movements, remarks, and actions

## Notes

The AI step is introduced as a pre-processing and data extraction layer, while the actual business workflow remains controlled by the human user and office routing process. This provides faster intake without removing validation and accountability.

## Setup and dependencies

### Download packages for OpenAI API and document processing

```bash
npm install @zxing/browser @zxing/library
npm install tesseract.js
npm install pdfjs-dist
composer require smalot/pdfparser
composer require openai-php/laravel
php artisan openai:install
php artisan vendor:publish --provider="OpenAI\Laravel\ServiceProvider"
```

## License

This project is a Laravel application and is intended for internal operational use within the organization.