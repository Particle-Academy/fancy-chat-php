# Changelog

All notable changes to this project are documented here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**This package is pre-1.0, so breaking changes land in MINOR releases.** The
version number is not a stability promise until 1.0; read the entry.

## [Unreleased]

### Fixed

- **`php artisan route:cache` no longer fails with "Serialization of 'Closure' is not allowed"** when the knowledge routes are mounted. Every `KnowledgeBridge::routes()` route (search, sources, a source's chunks, chunks, facts, facts/timeline, facts/all, ingest/jobs, ingest/worker, and POST facts/{fact}/accept and reject) was a closure holding the bridge and the host's scope closure. They are now `FancyChat\Prism\KnowledgeController` actions that resolve `KnowledgeBridge` from the container per request. URLs, methods, middleware and responses are unchanged. **What to do:** to use `route:cache`, bind `KnowledgeBridge` in a service provider (a cached app never runs its routes file) and call `KnowledgeBridge::routes(prefix: ..., middleware: [...])` with no bridge; a cached route with nothing bound throws a `LogicException` saying so. If you don't cache routes, do nothing: `routes(new KnowledgeBridge(...))` binds the bridge it is given.

### Changed

- `KnowledgeBridge::routes()`'s bridge argument is now optional (the container's binding is used), and mounting a second, different bridge throws a `LogicException`: every route serves the ONE bound bridge, so it would otherwise have served the first one's scope. One bridge per app was already the only tested shape; if you mounted two, bind one. New public `KnowledgeBridge::serve()`, the entry point the controller calls.

## [0.3.0] - 2026-11-19

### Changed

- **BREAKING:** Laravel 13 is the minimum; an app on Laravel 12 or older can no longer install this; upgrade Laravel. On Laravel 13, do nothing.
- Requires `laravel/framework` `^13.0`, because `src/` uses `Illuminate\Foundation`, which ships only in the framework. It moved from `require-dev` (where it was `^13.30`) to `require`.
- **Docs:** `AGENTS.md` is now the guide to using this package (install, how to find it on the Fancy MCP catalog server once it is listed, API essentials, traps). The contributor notes it held moved to `CONTRIBUTING.md`, and `CLAUDE.md` is now a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.
- **Docs:** the package is now listed on the Fancy MCP catalog server, so the README's MCP note and `AGENTS.md` say so, and `AGENTS.md` gives the browser-side install (`npm i @particle-academy/fancy-chat-js @particle-academy/fancy-chat-ui`). No code changed; nothing to do.
- CI: re-pin org third-party allowlist to 2614224

## [0.2.0] - 2026-11-16

**Read this first: fancy-chat-php is now built on Prism, and it absorbs
`particle-academy/fancy-chat-prism-php`.** The CC-fancy-0042 ruling was misread as
"no Prism in the core"; it meant the extras (prism-graph, prism-memory, the browser)
are optional (owner, 2026-11-16). If you used fancy-chat-prism-php, run
`composer remove particle-academy/fancy-chat-prism-php`: the class names are the same,
so no code changes (and `replace` removes it anyway).

### Added

- **Prism Harness is the backend, in this package.** `FancyChat\Prism\HarnessBackend`
  (the RunStep `g282.1` mapping, streamed approvals through `streamDecision`, cancel),
  `FancyChat\Prism\KnowledgeBridge` and `UnsupportedKnowledgeCall`, folded in from
  fancy-chat-prism-php 0.1.0 with their suite and sabotage list
  (`tests/sabotage-prism.php`, also in CI).
- **Attachments.** `HarnessBackend(attachments: fn (array $part, TurnInput $turn, ?array $author): Media|string|null)`:
  the host resolves each `image` / `file` part of a new message, with the author the
  server recorded. Prism media goes to Harness as `additionalContent` (checked by
  Harness's `TurnAttachments::admit` first), a string is a pointer for the model,
  `null` withholds it (`x-fancy-chat-prism.attachment-withheld`, named by label, never
  ref). Every attachment gets a prompt line, so an image-only message has a prompt.
  Without a resolver: metadata pointers only, nothing read.
- **`sourceTitle` on every chunk the KnowledgeBridge serves (CC-fancy-0044)**, from one
  scoped `ScopedGraph::getSource()` per distinct source (prism-graph 0.4). Absent, never
  guessed, when the scope cannot see the source or the store has no source lookup.
- **`KnowledgeBridge::available()`**, and `routes()` throws a `LogicException` naming
  `composer require particle-academy/prism-graph` when prism-graph is missing.

### Changed

- **BREAKING: requires `particle-academy/prism-harness >=0.26 <2.0` and
  `particle-academy/prism >=0.143 <2.0`.** Composer installs them; nothing to do.
- **prism-graph is suggested at `>=0.4`** (was `^0.3` in fancy-chat-prism-php), for
  `getSource`. Only the KnowledgeBridge needs it.
- `replace: particle-academy/fancy-chat-prism-php`: the two can never be installed together.
- **Conformance fixture re-vendored from fancy-chat-js, with three new cases.** A
  `plan.updated` with a null or non-object step is refused (`plan.step`), counted
  illegal, and the cursor moves past it. fancy-chat-js threw on the null step; this
  package already refused it, and passes the new cases with no code change. The
  pinned SHA-256 is now
  `295439897db175e8040f4a48effd5f55a18817aac342d2d39335dc030c39040b`. A new
  totality test feeds every core event type malformed shapes and checks
  `Projection::apply` never throws. It passes on 0.1.0 too, so it is coverage, not
  a fix. Nothing to do.

### Removed

- **BREAKING: `FancyChat\Backend\NullBackend`.** It was the scripted backend for tests
  and demos, and it now lives in this suite only (`tests/Support/ScriptedBackend`). What
  to do: bind `HarnessBackend`; in your own tests, implement `AgentBackend` in a few
  lines (see the lab's `LabQuestionBackend`) or drive Harness with Prism's fake.
- The no-Prism packaging test and its fixture.

## [0.1.0] - 2026-11-15

### Added (impactium follow-ups)

- **`ChatTurnSettled`** (after commit, once per settled turn, `succeeded()`): the hook
  for a "reply ready" notification. **`ChatTurnParking`**: runs before a parking turn's
  approvals are published.
- **Retry:** `POST threads/{id}/turns/{turnId}/retry` re-sends a failed, interrupted or
  cancelled turn's original parts as a new turn (human, `write` access; queued behind a
  running turn).
- **A turn links to its message** (fancy-chat-js 068bbe3): `turn.started` carries
  `messageId`; `TurnEntry.messageId` and the message's `turnId` are set. Fixture
  `38c2c9b3...`. Snapshot turns gain `messageId`: a host comparing a turn array exactly
  should compare with `messageId` included.

### Fixed (impactium follow-ups)

- **An approval was actionable before its turn parked.** A backend's approval was
  published the moment it streamed, so a human could decide before the run (and the
  evidence the host records about it) was durable. Backend approvals and questions are
  now held until the turn parks, then published after `ChatTurnParking`.
- **A decision made while the turn still ran was lost.** `resumeIfReady` returned early
  for a running turn, and nothing resumed it after it parked. The turn now resumes on
  its own once it parks (queue-and-resume), only on decisions made during that run.

### Added

- **`GET threads/{id}/capabilities`** answers `{capabilities: {steering, cancel,
  approvals}}`, and the snapshot response carries `capabilities` too, so a UI can show
  a running turn with no Stop when the backend cannot cancel. Booleans only: anything
  but `true` reads as false.
- **A resume carries every decided approval of the turn.** `TurnInput` gains
  `approvals` and `questions` (all of the turn's decided approvals and answered
  questions, in order); `approval` / `question` stay the one that triggered it.
  Nothing to do unless your backend parks on several approvals at once.
- **`RunTurn` has `$timeout` and `$tries`**, from `queue.timeout` (600) and
  `queue.tries` (1).
- **The actor's `label`** from the host resolver is kept (it was dropped).
- README: the 201 from send, the `{snapshot, seq, capabilities}` snapshot body.

### Fixed

- **The host's own `append` skipped the approval checks.** An `approval.requested`
  or `question.asked` appended by the host is now checked like a backend's and
  stamped with its turn. **An approval or question id already used in another
  thread is refused** (`409 approval.duplicate`): before, the HITL index was
  overwritten and a decision could be routed to the wrong thread. If you appended
  records that reuse ids across threads, give them unique ids.

- **Several thinking blocks per run, in order** (fancy-chat-js 6fbfe4c). A run that
  thinks, calls a tool and thinks again projects two thinking rows, each with a
  `thinkingId` (the event's `data.thinkingId`, else the run id, then `<runId>#2`...).
  A non-string `thinkingId` is illegal. Rows key thinking by `thinkingId`.
- **`durationExact` on a tool row**: `false` only when `tool.result` says so.
- Contract re-vendored: fixture `7d382bf7...`.
- **A tool result carries its output inline.** `tool.result` may carry `output`
  (the result text, or a failure's own words) and `outputLanguage`; the
  projection keeps both on the tool row. The server bounds `output` by
  characters at `limits.tool_output_chars` (default 16384) and marks a cut
  output `truncated`, so a tool that returns megabytes cannot flood every
  client. A non-text `output` is dropped. Plain text end to end, never HTML.
  Contract re-vendored: fixture `6cde3f5c...`.

### Fixed (since the phase 2 entries below, all unreleased)

- **Truncate cancels the turn a removed branch left parked.** Before, the new
  message queued behind a turn nobody could reach. A parked turn is settled
  `cancelled` (it has nothing running), a running one is cancelled through the
  backend, and the new message then starts its own turn.
- **A typed decision's message is now `decided`**, the contract's new final
  delivery state, and `POST messages` answers `delivery: "decided"` for it.
  It was recorded as `sent`, which read as delivered to the agent.
- Contract re-vendored: fixture `5ce5595a...`, machines `78959500...`.

### Added

- **First release: the Laravel server for the neutral agent-chat contract**
  (council change CC-fancy-0042). Nothing to migrate; this is new. Run
  `php artisan migrate` for its four tables.
  - The contract port (envelope, machines, parts, HITL, status, projection),
    passing every `php` section of the shared `conformance/chat-v1.json` to the
    same output as `@particle-academy/fancy-chat-js`. Both contract files are
    vendored byte-identical and pinned by SHA-256.
  - `ThreadEventStore` with database and memory drivers: an atomic, gapless
    per-thread seq (tested with concurrent writer processes), and a retention
    window whose pruned events fold into a checkpoint.
  - Routes for messages, SSE, long-poll, snapshots, cancel, unsend, approval
    decisions and question answers. A cursor older than retention gets
    `thread.reset` with the snapshot.
  - Decisions and answers accept human actors only, from the host resolver;
    the request body can never choose the actor. A staged write's `apply` runs
    only once a human decision record exists, then the parked turn resumes.
  - `AgentBackend` with a scripted `NullBackend`, the queued `RunTurn` job, and
    `fancy-chat:reap` for stalled turns.
  - The optional Echo/Reverb nudge, off by default, carrying only
    `{threadId, seq}`.
  - Every limit in `config/fancy-chat.php`.
  - No Prism dependency, enforced by a test with a negative control.
  - Laravel itself is a test dependency under the framework carve-out.
  - Typed approval, opt-in per app (`typed_approval.*`, off and with no
    phrases by default; `TypedApproval::ENGLISH` to opt into). A matching
    message takes the same decide path as the decision endpoint and is never
    delivered to the agent. Requires ext-intl, for NFKC.
  - Undo windows: `Undoable`, `FancyChat::undoable()` / `openUndo()`,
    `POST undo/{actionId}/revert` (humans only, before the deadline), and
    `fancy-chat:commit-undo` (the reaper commits too). Commit and revert each
    run at most once, under a lock. A fourth table, `fancy_chat_undo`.
  - Edit and resend: `supersedes` must name the author's own user message;
    `edit_resend.policy` is `branch` (the default) or `truncate`.
  - Plan-step HITL: a backend cannot release a held step before its approval.
  - `tests/e2e/serve.php`, a `php -S` router for end-to-end clients.
