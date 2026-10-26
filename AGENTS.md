# YoOhw Media Optimizer Project Instructions

These instructions apply to the entire `yoohw-media-optimizer` repository.

## Project authority

- `https://github.com/yoohwz/yoohw-media-optimizer` is the canonical Git repository. A Local Sites plugin directory without `.git` is a runtime copy; use a separate checkout for branches and PRs, preserve unrelated local changes, and do not initialize or replace its Git state implicitly.
- This repository is the source of truth for YoOhw Media Optimizer code, tests, metadata, and release preparation.
- `main` contains reviewed code. Do not push implementation work directly to `main`.
- WordPress.org publication, production deployment, release tags, and version decisions remain explicit Human actions.
- Runtime safety for media files takes precedence over convenience or refactor simplicity.

## Git safety and branch model

- Inspect branch/base state and the relevant diff before changing code.
- Preserve unrelated user work and never rewrite shared history.
- Never use force-push, destructive reset, or branch-protection weakening as part of normal task execution.
- Use `agent/<scope>` for normal feature, fix, test, tooling, and documentation work.
- Use `release/<version>` only for short-lived release preparation when needed.
- Concurrent Codex tasks must use separate worktrees or clean checkouts.
- Keep commits logically grouped and stage only files that belong to the task.
- Open or update a draft pull request for implementation work; the pull request is the primary durable execution record.

## Roles

- **Human** owns product direction, unresolved product/scope/safety trade-offs, merge approval, release/version decisions, production actions, and WordPress.org publication.
- **Codex** owns task framing, risk classification, technical architecture, planning, implementation, testing, review, corrections, work coordination, and branch/PR maintenance. One lead Codex remains accountable for the complete result and evidence, including delegated work.
- **Subagents** perform bounded discovery, implementation, testing, or independent review delegated by the lead Codex. They report evidence and findings to the lead; they do not replace Human authority or create another approval stage.
- **GitHub** is the durable record for plans, commits, pull requests, CI, review evidence, and Human decisions.

For this repository, these roles and workflow take precedence over generic role descriptions in global guidance. ChatGPT is not a required participant: task framing, planning, implementation, review, and acceptance evidence must not depend on a ChatGPT handoff or approval. Human retains product acceptance and all Human-owned decisions.

Human can give a goal directly to Codex. Codex resolves technical choices within the authorized scope and continues through validation and PR preparation without an external agent handoff. Ask Human only for missing information that blocks safe progress or a decision reserved to Human.

## Human command shorthand and routing

Keep Human commands short. Resolve `Chạy task này`, `Tiếp tục`, `Review`, and `Sửa tiếp` from the current conversation and durable GitHub state rather than requiring Human to restate known context.

- `Codex - Continue <TASK-ID>` recovers the latest applicable plan, decisions, PR head, CI, and findings, then performs the next Codex-owned action.
- `Codex - Review <TASK-ID>` reviews the current task/PR with the required independence and risk profile, fixes blocking findings within scope, and continues toward the Human gate.
- `Human - ...` identifies a Human-owned product decision, merge, release, or production action.

When a task ID or PR/issue reference is supplied, recover known context from GitHub. Inspect both top-level PR conversation comments and PR review submissions, ordered by time and exact-head applicability. If a review and its top-level mirror represent the same decision, use the top-level comment as the actionable handoff. A newer applicable review must not be hidden by an older handoff. Missing, contradictory, or genuinely ambiguous state must be reported specifically rather than guessed.

Legacy `PLAN_REVIEW_REQUIRED`, `TECHNICAL_REVIEW_REQUIRED`, and `TECHNICAL_CHANGES_REQUIRED` records are historical inputs, not active gates. On continuation, Codex recovers the original scope, plan, decisions, and unresolved findings, then performs the outstanding planning/review/correction work internally under this workflow. Preserve prior Human decisions; never infer approval of a safety-contract change from migration. Revalidate the current head before a new merge-ready handoff.

### Mandatory `Next:` hint

Every final user-visible workflow response with a clear next action must end with exactly one concise copy-ready hint:

`Next: <Target> - <short command>`

A durable issue/PR handoff must end with the same hint as the user-visible response:

- `READY_FOR_HUMAN_MERGE` -> `Next: Human - Merge PR #<N>`
- `HUMAN_DECISION_REQUIRED: <TASK-ID>` -> `Next: Human - Decide <TASK-ID>`
- Unfinished Codex work -> `Next: Codex - Continue <TASK-ID>`

`Next:` is Human UX, not a workflow status. Progress updates do not need a hint. Do not stop autonomous work merely to emit a hint. If there is genuinely no clear next action, no hint is required.

## Task brief

For **Fast Lane**, use only the minimum useful brief:

- Goal
- Scope
- Acceptance Criteria
- Validation
- Risk: Fast

For **Controlled Lane**, also record the affected invariants, implementation constraints, material risks, and unresolved decisions when any exist.

When product semantics could be ambiguous, the Controlled brief/plan must freeze a compact set of user-visible and edge-case scenarios before implementation. Keep the scenario table in the existing issue/plan; do not create another artifact or workflow status. Each row should identify the scenario, expected behavior, and whether it is in scope so distinctions such as sidecar cleanup versus original restoration are resolved before code changes.

Controlled plans must also state a proportional complexity budget and stop-loss. Record the expected runtime surfaces and whether DDL, a new lock domain, a new recovery coordinator, or another destructive authority is expected. If implementation unexpectedly requires a new persistent schema/migration, lock/transaction/recovery domain, cross-subsystem destructive authority, or repeated blockers make the architecture poor value, stop the affected implementation and revise the plan with proportional independent subagent review before resuming. Use `HUMAN_DECISION_REQUIRED` only when the revision requires a Human-owned product/scope/safety decision. Do not silently expand scope or weaken an invariant.

Do not create repository planning documents merely to mirror conversation or pull-request history.

## Task and pull request shape

- Use one task = one draft pull request by default. Use logical commits as rollback and review checkpoints rather than mechanical tranche PRs.
- Split a task only at a real semantic boundary: an independently mergeable schema/migration, a distinct destructive authority, an intentional Human/product decision between parts, a dependency on accepted updated `main`, or a diff too broad for safe semantic review.
- The one-PR default does not authorize mega-PRs. Reviewability, safety, and the complexity stop-loss remain binding.

## Risk lanes

Use only `Fast` and `Controlled`.

### Fast Lane

Use Fast Lane when the change is isolated and cannot materially alter media integrity, destructive execution, persistence contracts, or security boundaries. Typical examples:

- documentation, copy, or translation;
- test-only changes that do not alter runtime behavior;
- CI, tooling, repository contracts, and governance;
- narrow CSS/admin presentation changes;
- small understood bugs outside safety-critical behavior;
- local refactors that preserve public, persistence, and media-safety contracts.

Normal flow:

`Human goal -> Codex brief + implementation + validation + proportional review -> PR + applicable final validation -> Human merge gate`

No separate plan approval or mandatory subagent is required. Use a subagent when a bounded parallel task or independent check materially improves confidence; a small, well-understood change can use the lead Codex's own verification.

### Controlled Lane

Use Controlled Lane whenever implementation changes or may change any of these behaviors:

- original JPEG/PNG optimization, resizing, recompression, metadata policy, or repeat-optimization fingerprints;
- WebP/AVIF sidecar generation, replacement, freshness checks, discovery, or cleanup;
- backup creation, private storage, legacy migration, references, or restore behavior;
- file deletion, source selection, filesystem path containment, or symlink handling;
- attachment metadata mutation or reconciliation;
- persistent queue schema, migration, ownership, stale-item handling, cursor, resume, or cancellation semantics;
- destructive-operation locking or concurrency;
- AJAX, admin-post, WP-CLI, capability, nonce, authorization, or other security boundaries;
- frontend delivery selection, picture fallbacks, caching behavior, or Delivery Assistant requests;
- external binary invocation, arguments, temporary files, or image-editor fallback behavior;
- behavior that can materially affect a large Media Library;
- release or WordPress.org publishing semantics.

Classification is semantic, not filename-based. A text-only edit inside a safety-critical file can remain Fast; a seemingly small helper change that weakens an invariant is Controlled.

Normal flow:

`Human goal -> Codex discovery + recorded plan -> implementation + tests/runtime evidence + independent review where required + corrections -> PR + applicable final validation -> Human merge gate`

Codex records the Controlled plan before runtime implementation. Use the existing issue or draft PR; do not create a separate issue solely for plan approval. Resolve technical planning and review internally. Obtain Human decisions before implementing changes to product scope, safety posture, or other Human-owned choices.

For safety-critical changes or a new destructive/persistence/locking architecture, have a fresh subagent challenge the plan's scenarios, invariants, complexity, and validation strategy before implementation. This is a focused technical check, not a cross-agent workflow gate. Record material findings and corrections with the plan. Later implementation review checks the actual diff and evidence, not a repetition of the planning exercise.

Complete `implement -> tests/runtime evidence -> proportional adversarial review -> fix blocking findings -> exact-head final validation -> Human handoff`. Codex remains responsible for plan/diff alignment, architecture and invariants, safety boundaries, scope, compatibility, acceptance criteria, and evidence sufficiency.

If Fast-Lane discovery reveals a Controlled risk, stop before risky runtime implementation and promote the task to Controlled Lane.

### Validation/review profiles

Profiles tune evidence intensity inside the existing Fast/Controlled lanes; they are not a third risk lane and do not add workflow statuses:

- **Structural** — behavior-preserving move, extraction, or refactor. Prove source/body/API equivalence where practical, characterize dependency boundaries, and run targeted tests.
- **Functional** — product behavior changes without destructive or safety-critical authority. Exercise the changed behavior and affected integration/browser boundaries.
- **Safety-critical** — deletion, metadata transaction/reconciliation, persistence/schema, locking/concurrency, authorization/security, crash recovery, or an equivalent irreversible state. Use the strongest relevant integration/runtime evidence and the mandatory fresh independent review rules below.
- **Release** — package, publisher, SVN, tag, or release-control work. Validate the exact package, Plugin Check, and release contracts without crossing the Human publication gate.

Record the selected profile in the PR evidence. Risk-lane classification remains authoritative if a profile and lane appear to conflict.

### Review intensity: fresh independent Codex review

A fresh independent Codex review is a **review intensity**, not a separate workflow stage or status. It runs inside the Codex execution cycle before the Human merge handoff.

A fresh independent Codex review is mandatory for safety-critical Controlled changes that materially touch any of these surfaces:

- file deletion, destructive filesystem work, or path-containment enforcement;
- metadata transactional integrity or destructive metadata reconciliation;
- persistent schema or migration behavior;
- concurrency, locks, worker ownership, cancellation, or replay safety;
- authorization, nonce/capability enforcement, or other security boundaries;
- crash recovery or transactional recovery logic;
- irreversible migration or similarly hard-to-undo state transition.

The independent review must use a fresh subagent context and a separate worktree or clean checkout, bind to the exact PR head, and produce findings that the lead Codex resolves before posting `READY_FOR_HUMAN_MERGE`. Give the reviewer the task, plan, invariants, diff, and evidence without the implementer's private reasoning. Record the reviewer, reviewed SHA, findings, and corrections in the PR. If the head changes, review the delta and affected invariants against the new head; do not reuse stale approval. If independent review is unavailable, report the missing evidence and do not claim merge readiness. Human may require independent review at any time.

Other Controlled tasks may use the implementing Codex's own adversarial verification plus strong automated/runtime evidence when a fresh reviewer would add disproportionate overhead.

A pure mechanical Structural change does not require a fresh independent reviewer merely because its source file belongs to a safety-critical subsystem, but only when semantic behavior is explicitly unchanged, exact source/body/API equivalence is mechanically proven where practical, dependency-boundary characterization tests exist, and no lock, persistence, destructive authority, metadata semantics, recovery ordering, authorization, or public/persisted contract changes. If any condition is not met, apply the stronger review intensity.

## Media safety invariants

These rules apply to sidecar generation/cleanup, original optimization, backups, restore, media paths, attachment metadata, delivery, and persistent queues. Read the affected code, `readme.txt`, `uninstall.php`, and `tests/README.md` before changing these behaviors. These are preservation requirements for future changes, not a claim that every edge case already has automated coverage.

The following invariants are non-negotiable unless the Human explicitly approves a contract change through Controlled Lane:

1. Sidecar generation must not replace original uploads. Original JPEG/PNG optimization and HTML delivery remain opt-in.
2. Original optimization requires a successful backup before replacement. Existing backups must retain the recoverable original rather than being overwritten by an optimized file.
3. New backups remain outside the WordPress document root and isolated by site. Legacy migration must copy successfully before removing the old backup; failure must not destroy the only recovery source.
4. Sidecar cleanup deletes only safely resolved generated WebP/AVIF files inside the canonical uploads boundary. Original uploads and backups must never become cleanup candidates; symlink escapes must not bypass containment.
5. Restore copies from the correct backup before removing associated sidecars or marking a file restored. Partial failure must not report failed files as restored or discard recovery data.
6. Repeat optimization must preserve source/options fingerprint checks so an unchanged source is not silently subjected to repeated lossy compression. Changes to force behavior require explicit scenarios and evidence.
7. Queue workers preserve lock ownership, token-checked release/refresh, stale-item handling, bounded batches, and persisted progress. Late or concurrent workers must not bypass the applicable ownership checks.
8. Metadata and savings/tracking updates must reflect the files actually processed or restored. Failure paths must not leave false success state.
9. HTML delivery preserves the original JPEG/PNG fallback, uses fresh available sidecars, and remains safe under page caching. Delivery Assistant must not silently modify server/CDN configuration or broaden requests beyond the intended site boundary.
10. Administrative mutations retain capability and nonce protection, and CLI/file operations retain their applicable authorization and path checks. External tools remain optional, local, and safely invoked.
11. Uninstall preserves generated sidecars and backups; removing options/tracking must not silently become media deletion. Multisite operations preserve site isolation.

## Subagent coordination

- Delegate only bounded work with a clear objective, scope, inputs, and expected evidence. Use parallel agents for independent work when it reduces effort or risk; do not require a fixed roster or agent count.
- Lead Codex owns integration and resolves conflicting recommendations using repository evidence. Subagents do not approve their own implementation as independent review.
- Concurrent implementation tasks use separate worktrees or clean checkouts. Avoid overlapping file ownership; integrate changes deliberately and validate the final head.
- Separate plan critique from implementation verification. Re-review corrections as a delta unless the scope materially expands. Do not add serial review rounds for advisory-only findings.

### Autonomous model and effort selection

- Human authorizes the lead Codex to proactively spawn and assign subagents using **GPT-6.1 Sol** (`gpt-6.1-sol`) or **GPT-6 Astra** (`gpt-6-astra`), and to choose or revise each subtask's model and supported reasoning effort without asking for approval. This authority covers subagents; it does not require changing the main agent's model.
- Sol is the default for discovery, planning, implementation, testing, and independent review, including Controlled and safety-critical work. Choose effort per work item using observed complexity, ambiguity, risk, evidence quality, and progress: low/medium for straightforward bounded work, high or higher supported effort when deeper reasoning is justified. Do not default every subtask to the highest setting or create extra agents for trivial work.
- Use Astra only for a bounded reasoning problem with a concrete unresolved technical question or risk. The lead must identify why additional evidence, decomposition, or increased Sol effort has been insufficient, or is clearly unsuitable for that problem, and what result Astra is expected to provide. Do not require repeated failed Sol attempts when the technical case is already clear.
- A Controlled/safety-critical label, architecture work, or an independent-review role alone does not justify Astra. Missing permissions, unavailable tools, environment failures, and CI infrastructure failures are not model-escalation reasons; address their actual cause.
- Independent review requires a separate reviewer, fresh context, and a separate checkout where required above; it does not require a different model. Keep the same independence, exact-head, and evidence requirements when the reviewer uses Sol.
- Limit Astra to the justified question or review scope and use proportionate supported effort. Return remaining routine work to Sol once that need is resolved. Record the technical reason, bounded objective, and outcome briefly in existing PR evidence; do not introduce quotas, separate forms, or another approval gate.
- Apply model/effort changes through the available agent controls. If an active agent cannot be reconfigured, stop or finish its bounded work and reassign the remaining work with the chosen settings. Preserve relevant findings and avoid concurrent duplicate implementation; never claim an unavailable runtime switch occurred.
- When explicit model/effort overrides require a fresh context (such as `fork_turns="none"`), provide the objective, scope, repository/worktree, binding decisions, and expected evidence explicitly. Independent review must still meet the fresh-context, separate-checkout, and exact-head requirements above.
- Model/effort choice does not change scope, safety invariants, validation obligations, or Human authority. Main-agent autonomy remains intact within these rules; no additional Human approval is required for a justified escalation.

## Status protocol

Use only these statuses when handing work back to Human:

- `HUMAN_DECISION_REQUIRED`
- `READY_FOR_HUMAN_MERGE`

Planning, implementation, testing, internal findings, and CI progress belong in normal PR evidence; they do not require workflow statuses or Human relays between agents. Missing evidence or failed CI means the work is unfinished, not merge-ready.

## Durable handoffs and review routing

- The PR is the primary execution record. Reuse an existing task issue when useful; a separate issue or planning document is not mandatory.
- Record the brief, Controlled plan when applicable, binding Human decisions, semantic changes, affected invariants, a PASS/NOT RUN evidence table, blocking findings and corrections, and the release boundary. Keep detailed logs in linked artifacts where practical.
- Before a merge-ready handoff, complete required local verification, independent review where required, and applicable final validation on the current exact head. Update the PR body and post one canonical top-level comment with `STATUS: READY_FOR_HUMAN_MERGE`, task/PR identity, head SHA, evidence, and `Next: Human - Merge PR #<N>`.
- For a blocking Human-owned decision, post `STATUS: HUMAN_DECISION_REQUIRED` to the existing issue or PR with the concrete decision, options, recommendation, and matching `Next: Human - Decide <TASK-ID>`. Continue independent authorized work where possible.
- A commit after a merge-ready handoff invalidates that handoff. Codex completes affected checks/review and applicable final validation, then posts a new exact-head handoff. Do not merge based on stale evidence.
- Subagent findings and optional GitHub review submissions are supporting evidence. The lead resolves blockers and produces the single Human handoff; do not duplicate every internal review as a workflow transition.

For corrections, inspect the delta from the previously reviewed SHA first, verify the original blockers and affected invariants, then spot-check the final PR state. Do not repeat a full review from zero unless the change surface materially expanded.

## Review severity

Only blocking findings should create another correction cycle. Blocking findings include correctness, safety, security, regression, acceptance failure, broken required CI, or contract violation.

Naming preferences, optional cleanup, and future refactors are advisory. Advisory-only findings do not prevent `READY_FOR_HUMAN_MERGE`.

## Validation routing

Validation must be proportional to the change:

- documentation/governance: repository contracts and diff hygiene;
- structural PHP move: source/API equivalence, dependency-boundary characterization, and targeted regression checks;
- PHP/runtime changes: PHP syntax checks, applicable WordPress coding/compatibility checks when available, and targeted integration checks against the declared support boundaries;
- Queue persistence: storage, resume, stale-item handling, and replay tests;
- locking/concurrency: cross-process contention and stale-owner evidence;
- sidecar generation/cleanup: source protection, freshness, containment/symlink checks, and partial-failure evidence;
- original optimization/restore: backup-before-write, backup migration, repeat fingerprints, metadata consistency, and real image-editor evidence;
- delivery: AVIF/WebP selection, original fallback, cached HTML, and same-site request boundaries;
- external binaries: argument/path safety, unavailable-tool fallbacks, and failure cleanup;
- AJAX/security: capability, nonce, authorization, and transport evidence;
- JavaScript orchestration: syntax checks, applicable automated contracts when present, and the changed browser flow;
- UI/CSS: targeted visual/manual evidence only for the changed surface;
- release: repository/release contracts, exact package validation, Plugin Check, and relevant safety smoke tests.

Do not claim a check passed if it did not run. Record unavailable checks as `NOT RUN — reason`.

A green CI run proves only the checks CI actually executes; it does not replace targeted runtime evidence when the changed behavior requires it.

## Repository validation and CI

- Keep the PR body current with goal, scope, risk lane, changes, validation, runtime evidence/limitations, and release boundary.
- The repository currently has no GitHub Actions workflows, Composer/PHPCS/PHPUnit configuration, Node test suite, CI classifier, or aggregate `CI Gate`. Do not claim that Thumbnail Manager's CI jobs exist here or copy its matrix, release scripts, baselines, or publisher without adapting and validating their dependencies.
- Read the plugin header and `readme.txt` for the declared WordPress/PHP support boundaries. Do not change version or compatibility declarations merely to make tooling pass.
- Existing integration entry point, from a disposable/local WordPress site's root:

  ```bash
  wp eval-file wp-content/plugins/yoohw-media-optimizer/tests/regression-tests.php
  ```

  Read `tests/README.md` and the script first. It writes temporary media and changes/restores plugin options; run it only against an appropriate test site. Its current coverage includes picture fallbacks, sidecar containment, queue lock exclusion, repeat optimization, and private backup placement. Image-engine-dependent assertions require the relevant extension; a success message alone does not prove those branches ran.
- For documentation/governance changes, validate repository references, scope, diff hygiene, and exclusion of development instructions from release packaging. Runtime tests are unnecessary unless runtime code changes.
- For PHP changes, lint the changed PHP files and run the regression entry point plus targeted checks required by the affected behavior. Syntax checks alone do not establish media safety. For JavaScript changes, run `node --check assets/admin.js` and exercise the affected admin flow.
- Record each required check as `PASS`, `FAIL`, or `NOT RUN — reason`. If required safety evidence cannot be obtained, the task remains unfinished; do not claim merge readiness.
- Until automated CI is configured, PR handoffs explicitly state `CI: NOT RUN — no GitHub Actions configured` and identify the exact head and applicable local evidence. This is a tooling limitation, not evidence of a passed CI gate. Do not invent an additional Human approval stage or block documentation-only work solely on absent CI.
- When CI is explicitly in scope, follow the same Iteration/Final distinction as Thumbnail Manager: draft heads may run applicable representative checks; ready-for-review heads, `main` pushes, and manual dispatches run all applicable final checks. Document the actual supported matrix and job names in the same change.
- A future aggregate `CI Gate` must fail closed: a required job that fails, is cancelled, or is skipped cannot count as merge-ready. Iteration-only results must not satisfy final branch protection. CI control-plane changes must validate the complete graph they define.
- Cache only immutable dependency downloads keyed by lockfile/runtime identity; do not hide upstream drift with cached mutable WordPress sources or generated state. Do not bypass a failed required gate.
- CI evidence applies only to the checked head. After a correction, repeat affected verification and all applicable final checks before a new handoff. Green automation never replaces required runtime evidence.

## Release boundary

- Normal feature/fix PRs do not publish to WordPress.org and do not create release tags.
- Keep version metadata unchanged unless version/release work is explicitly in scope.
- Release preparation and publishing are separate Human-gated actions.
- Do not delete release branches, create tags, publish to WordPress.org, or deploy production code unless explicitly requested.
