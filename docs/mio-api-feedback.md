# MIO API review for AllTerrain Forms

Reviewed 14 September 2026. Developer handoff for [OpenStation PR #816 — Add window-scoped MIO assistance and private Settings actions](https://github.com/WordPress/openstation/pull/816), head `07790d6a3320b250b548ef132aa4b7d60a0f5ac3`, branch `codex/mio-window-assistant` in the sibling alcazaba-plugin checkout. The checkout was read and its assistant tests run; no upstream files were edited and no GitHub comment was posted.

## Recovery follow-up: implemented upstream and now adopted

The developer addressed the recovery requests in commit `8eadeb9a0fe4f300114f7ab5a3f224b12f346d88`. Forms now declares read/validate/write effects, returns structured rejected/confirmed outcomes, binds edits to turn IDs, compacts history to one complete candidate, supplies documentation metadata, and exposes a read-only operation-status resolver. The newer API supports up to 40,000-byte editor YAML; the original API retains the 16,000-byte limit. A conformance test imports the actual upstream session and completes a 35 KB document edit with two corrections and one confirmed save under the 96 KB transcript limit.

Server operation keys are scoped to the acting user, atomically claimed and bound to the normalized payload. Successful saves store a durable receipt; an identical replay returns the original result while that revision remains current. In-flight or conflicting replays are rejected. Metadata contains hashes/IDs, not YAML, and is retained for seven days. Unknown/expired status never authorizes automatic resubmission. The original findings below remain as historical review context; they no longer describe the recovery commit as missing those features.

**Response buttons are now implemented in the developer’s working checkout and consumed by Forms.** See the [Preview action buttons handoff](mio-action-buttons-proposal.md) for the original plan and current implementation status; the shell change is still unmerged.

## Compatibility recheck after the final local fixes

Rechecked 14 September 2026 against the current `alcazaba-plugin` working tree. Both local HEAD and the remote `codex/mio-window-assistant` ref still resolve to `8eadeb9a0fe4f300114f7ab5a3f224b12f346d88`; the newer response-action, textarea, focus, Unicode-help and residency-cleanup fixes are uncommitted changes on top. The Docker 8889 desktop and MIO bundles match that checkout's built files by SHA-256.

No Forms compatibility regression was found. The optional public fields remain additive; detached-editor disposal is idempotent with Forms' own observer, and the new textarea/input events are internal to the shell. Forms' 28 focused adapter/contract/builder tests and TypeScript check pass. The upstream 75 relevant assistant/recovery/action/chat/residency/textarea tests also pass.

All four Forms browser scenarios pass on Docker 8889: YAML export/import, original MIO adapter, recovery adapter, and the actual rendered MIO chat. The new `tests/e2e/mio-chat.spec.ts` replaces only provider responses, types through the real textarea, receives two validation failures, saves once, clicks the rendered Preview action and verifies the conditional field in the native preview. It also checks Shift+Enter, clearing the composer, reopening the action and Escape focus restoration. Preview causes no extra provider round or save. No runtime adaptation was required; the new browser regression test is retained in Forms.

## Integration implemented in Forms

Forms registers the actual native window instance with `wp.os.mio.registerWindow`. The lease owns a connected editor host, dynamic prompt, linked local Markdown documents and four private tools: begin_form_edit, list_form_options, validate_form_yaml, apply_form_edit. Tools do not enter the global WordPress Abilities registry. The shell retains control of focus, enablement, provider choice and Ask MIO.

The [knowledge index](mio/index.md) covers 37 components, all registered CSS tokens, fields, conditions, notifications, confirmations, actions, settings, privacy, quizzes and validation. MIO edits a complete `{title,schema}` YAML document using local theme/media references; the file exporter uses the versioned package envelope with embedded dependencies. Both share the form JSON Schema and PHP normalization checks. The [conditional contact recipe](mio/recipes/conditional-contact.md) implements the requested name/surname/Other example.

Validation returns structured outcomes and allows the first attempt plus two corrections. A successful validation creates a single-use, in-memory receipt; apply accepts the receipt rather than replacement YAML. Server apply validates again and checks the stored revision. New forms are drafts, existing status is retained. The revision guard is optimistic, not a transaction across every form writer. Cancellation is not rollback and a lost response is not retried as a new write.

## Requested changes before wider adoption

### 1. Recoverable argument-validation errors — high priority

[session.ts lines 125–143](https://github.com/WordPress/openstation/blob/07790d6a3320b250b548ef132aa4b7d60a0f5ac3/src/mio/assistant/session.ts#L125) parses JSON arguments and calls a boolean validator. Malformed JSON, false from validate, or a thrown run exception ends the entire turn. The model cannot correct a typo, even though no write happened. This blocks the basic “validator tells the model what to fix, then retry” interaction if consumers put semantic validation in validate.

Suggested contract: allow a structured validation result such as `{ok:false, errors:[{code,path,message,suggestion}], retryable:true}`. Feed recoverable errors back as tool results, retaining the same turn. Keep authorization failures, unavailable actions, cancellation and uncertain writes terminal. Invalid JSON should become a bounded argument error when the offered tool can be identified safely. Do not execute run until the envelope is valid.

Forms workaround: the boolean validator checks only argument shape. YAML parsing and server semantic validation happen inside run and return a structured `saved:false` result. This lets current MIO recover from malformed YAML inside a valid tool envelope, but cannot repair malformed tool-call JSON or wrong outer argument types.

Acceptance test: model supplies invalid arguments, receives a precise path, corrects twice, then succeeds once. Exhaustion ends with no write. Permission errors are never retried as validation.

### 2. Separate reads, validation failures and writes in deduplication — high priority

[session.ts lines 134–146](https://github.com/WordPress/openstation/blob/07790d6a3320b250b548ef132aa4b7d60a0f5ac3/src/mio/assistant/session.ts#L134) records `[name,args]` as completed **before** run, regardless of whether it reads data, validates unsuccessfully or writes. Re-reading the current form or the same help page throws a repeated-action exception. Even a non-writing validation failure is counted as a completed action. The catch text then reports earlier “actions completed,” which can misleadingly sound like saves.

Add ability metadata such as `effect: 'read' | 'validate' | 'write'`. Bound repeated reads to prevent loops, while allowing necessary refreshes. Mark writes completed using explicit outcomes/receipts. A validation failure should carry `effect:'none'`; failure summaries should distinguish reads, rejected candidates, confirmed writes and unknown write outcomes.

Acceptance test: read current form, apply one edit, read current form again. Both reads succeed; the same write receipt cannot be consumed twice. Repeated validation of unchanged invalid YAML produces feedback or a specific retry-budget error, not a claim that a save completed.

### 3. Budget tool history for complete documents — high priority

[session.ts lines 90–98 and 146](https://github.com/WordPress/openstation/blob/07790d6a3320b250b548ef132aa4b7d60a0f5ac3/src/mio/assistant/session.ts#L90) resends all outcomes, including full tool arguments, each round. [Server limits](https://github.com/WordPress/openstation/blob/07790d6a3320b250b548ef132aa4b7d60a0f5ac3/includes/ai-copilot/mio.php#L66) cap transcript at 96,000 bytes, prompt at 16,000, tool definitions at 96,000, and the full request at 220,000. Reading a document then validating several copies consumes that allowance quickly; help excerpts and escaped YAML add more bytes.

Allow an app to supply a compact history representation, e.g. `{editId, documentHash, byteLength, errors}`, and keep only the latest necessary candidate. Measure UTF-8 serialized request size before transport. Surface a structured budget error with remaining bytes instead of a generic provider failure. Do not silently truncate a form definition. Prefer application-owned draft resources and edit/diff operations for larger forms.

Forms workaround: 16,000-byte assistant YAML limit, no binary assets, bounded validation errors and short per-topic docs. File import/export remains available for larger forms. This reduces risk but is not a proof that every 16 KB form plus arbitrary help/history fits 96 KB. A correct retry workflow needs shell-level compaction.

Acceptance test: complete a 40 KB form edit with two repair attempts and relevant help reads without silently losing fields or exceeding the server transcript cap.

### 4. Expose help truncation and precise retrieval — medium priority

[help.ts lines 28–36 and 58](https://github.com/WordPress/openstation/blob/07790d6a3320b250b548ef132aa4b7d60a0f5ac3/src/mio/assistant/help.ts#L28) returns four substring-ranked hits with 3,200-character excerpts, and read_help slices at 12,000 characters without a truncation flag or continuation. A critical rule after that boundary becomes invisible. Common words can outrank the exact component needed.

Return `truncated`, section identifiers and a continuation cursor or section-aware read. Rank exact document/title/ID matches above common-word matches; ignore stop words. Add manifest metadata for version, topics and component IDs. This can stay local and deterministic: embeddings are optional, not required for useful Markdown retrieval.

Forms workaround: topic-sized linked files under 12,000 characters, explicit headings and a tested link graph. Every built-in component has its own file. The current reference is lexical RAG, not an embedding/vector database.

### 5. Give consumers a turn identity and operation lifecycle — medium priority

[types.ts](https://github.com/WordPress/openstation/blob/07790d6a3320b250b548ef132aa4b7d60a0f5ac3/src/mio/assistant/types.ts) passes args and AbortSignal to run, but no stable turnId/callId, retry budget, effect status or persistence receipt. Consumers cannot enforce “three validation attempts per user request” across repeated begin calls, bind a change to a specific user turn, or reliably reconcile a request accepted before cancellation.

Pass a context object with turnId, callId and signal, plus explicit begin/end/abort lifecycle hooks. Permit app-level server idempotency keys and a read-only operation status endpoint. Include a window/document revision in context. Keep server authorization authoritative. A request ID should identify one logical write, not automatically authorize replay after an unknown outcome.

Forms workaround: per-edit attempt count, prompt instruction not to restart to evade the limit, single-use receipt, root/editor fingerprint, server revision and AbortSignal. A model that opens a fresh edit can reset the per-edit counter; the shell's existing eight-round/sixteen-call ceiling still bounds work. Strong per-user-turn enforcement needs the API lifecycle.

Acceptance test: close/focus-switch during a save, then inspect the operation status; never double-create a draft. Starting another edit within the same user turn cannot reset its correction allowance.

## Existing API strengths to retain

Window-bound leases, private tool manifests, dynamic allowed checks, local document retrieval, sequential execution and abort on context loss are the right foundations. The server transport offers only the current window's tools and does not become a broad “execute any WordPress ability” endpoint. Keep the AI provider and user preferences under shell control.

## Validation evidence and limits

The upstream `tests/vitest/mio-assistant.test.ts` suite passes (13 tests) at the reviewed head. Forms has tests for two repairs followed by one save, retry exhaustion, malformed YAML, structured errors, stale editor state, cancellation, cross-context receipts, unknown save outcomes, document links and lease cleanup. WordPress tests exercise dry-runs, strict booleans, semantic references, theme tokens, draft creation, status retention, stale stored revisions and permissions. Browser tests target Docker `http://localhost:8889` and simulate model tool calls while retaining the real shell lease and WordPress REST calls; they do not assert a live provider's natural-language quality.

The full PHP run on this Docker environment also reproduces eight pre-existing Gravity Forms/WPForms importer failures (3 errors, 5 assertions). The same failures reproduce from unchanged Git HEAD in a separate temporary test checkout. They are separate from the new assistant/package tests; no upstream or Core code was changed to conceal them.

The developer subsequently added the optional response action API in the working checkout. Forms now consumes it for a receipt-bound Preview button; the [separate action document](mio-action-buttons-proposal.md) records the proposal and implementation follow-up. The actual session/action-registry conformance tests cover one preview click with no additional AI call or save.
