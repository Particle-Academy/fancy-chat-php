# Changelog

All notable changes to this project are documented here, in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

**This package is pre-1.0, so breaking changes land in MINOR releases.** The
version number is not a stability promise until 1.0; read the entry.

## [Unreleased]

### Added

- **First release: the Laravel server for the neutral agent-chat contract**
  (council change CC-fancy-0042). Nothing to migrate; this is new. Run
  `php artisan migrate` for its three tables.
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
