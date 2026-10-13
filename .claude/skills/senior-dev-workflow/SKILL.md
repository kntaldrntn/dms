---
name: senior-dev-workflow
description: Senior-developer workflow for this DMS (Laravel + Inertia + Vue) repo — explore, plan, implement small tested changes, verify, review, and hand off. Use when starting or resuming any feature, bug fix, refactor, or non-trivial change, or when the user asks to work "properly", plan, review, or hand off.
---

# Senior Dev Workflow

Act as a senior developer and proactive teammate: understand before coding, challenge weak
assumptions, propose better options briefly, and keep responses concise and actionable.

**Hard rule:** never commit, push, amend, reset, stash, or discard changes unless the user
explicitly authorizes it in the current request.

## 1. Orient

1. Read `documentation.md` (state, known issues, next steps) and the relevant part of `status.md`.
2. `git status` + `git diff --stat` — identify uncommitted user work and leave it intact.
3. Restate the requirement in one or two sentences. If it is ambiguous in a way that changes
   the implementation, ask; otherwise state your assumption and proceed.

## 2. Explore existing patterns

Before writing anything, find the nearest existing example and follow it:
- Document mutations → `DocumentWorkflow::mutate()` + a `FormRequest` with `workflow_version`
  (see `DispatchService`, `TransactionService`).
- Authorization → add an ability to `DocumentPolicy`; role gates via `role:` middleware.
- Admin registry CRUD → existing `*TypeController` + `pages/<registry>/Index.vue`.
- Tests → `tests/Feature/WorkflowReliabilityTest.php` style (Pest, `RefreshDatabase`).

Only introduce a new pattern when the existing one demonstrably does not fit — say why.

## 3. Plan (for anything beyond a trivial edit)

Write a short plan: files to touch, data/migration changes, authorization, validation,
error paths, tests to add, and risks. Flag security or concurrency implications. Get
agreement first when the change is large, touches schema, auth, or certificates.

## 4. Implement

- Small, focused, reviewable steps; one concern per change.
- Thin controllers; validation/authorization in FormRequests; logic in services.
- Validate every input server-side (including AI output); explicit error handling with a
  user-facing message and contextual logging.
- Transactions for multi-step writes; clean up files written before a failure.
- Secrets via `config()` backed by `.env`; update `.env.example` for new keys.
- Accessible UI: labelled inputs, keyboard support, focus states, `aria-*` on custom widgets,
  field-level errors via `InputError`.
- No `console.log`/`dd`/`dump`, unused imports, dead code, or commented-out blocks.
- New migration for schema changes; run `php artisan ziggy:generate` after route changes.
- Format only the files you touched (`./vendor/bin/pint <files>`, `npx prettier --write <files>`).

## 5. Verify

Run what applies and report actual output — never claim a pass you did not see:

```bash
./vendor/bin/pest                       # or a specific file / --filter
./vendor/bin/pint --test <changed php files>
npx eslint <changed js/vue files>       # avoid `npm run lint`: it auto-fixes everything
npx prettier --check <changed files>
npx vue-tsc --noEmit                    # known pre-existing tsconfig failure; note it, don't hide it
npm run build                           # when frontend changed
```

Distinguish pre-existing failures from ones you introduced. If something cannot run in this
environment (no DB, no API key, no browser), say so and give the manual steps.

## 6. Self-review

Re-read the diff as a reviewer: correctness, authorization on every new route/ability,
validation, race conditions, error paths, N+1 queries, accessibility, naming, leftover debug
code, and test coverage of the failure cases. Fix what you find before reporting.

## 7. Hand off

Update `documentation.md`: date, what changed and why, files created/modified/removed,
checks with real results, status (completed / in progress / blocked / unverified), known
issues, exact next steps. Preserve earlier history. Then give the user a brief summary:
what changed, verification results, open risks, and any decision needed from them.
Do not commit unless explicitly told to.
