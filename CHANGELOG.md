# Changelog

All notable changes to this project are documented here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**This package is pre-1.0, so breaking changes land in MINOR releases.** The
version number is not a stability promise until 1.0; read the entry.

## [Unreleased]

### Added

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
