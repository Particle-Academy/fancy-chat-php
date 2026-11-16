# particle-academy/fancy-chat-php

The Laravel server for the Fancy agent chat framework (council change
CC-fancy-0042): an append-only thread log with an atomic, gapless per-thread
`seq`, SSE plus long-poll with resumable replay, the turn machine, staged writes,
and decision endpoints that accept **human actors only**.

It is the PHP twin of `@particle-academy/fancy-chat-js`. Both pass the same
conformance fixture (`conformance/chat-v1.json`) to the same output and read the
same transition table (`contract/machines.json`); both files are vendored
byte-identical and pinned by SHA-256 in both repositories.

**Provider-neutral.** It works with any agent backend, or none, and has no
Prism dependency (a test enforces that). Prism adapters are an optional addon
in `particle-academy/fancy-chat-prism-php`.

**Not yet published.** Status: phase P1 of `.ai/plans/agent-chat-framework.md`.

## Install

```bash
composer require particle-academy/fancy-chat-php
php artisan migrate
```

Until the first Packagist release, require it from git: add
`{"type": "vcs", "url": "https://github.com/Particle-Academy/fancy-chat-php.git"}` to
`repositories` and require `particle-academy/fancy-chat-php:dev-main` (or
`dev-main#<sha>` to pin a commit).

Laravel 13, PHP 8.4.

## Bind the host

Nothing works until the host binds an actor resolver and an access resolver:
every route answers `403 chat.unconfigured` until then. No user model is
assumed; an actor is `{kind, id, label?}` from your own resolver. `label` (a
display name, a non-empty string) is kept on the events the actor writes and on
the messages they author; it is shown, never compared: decisions match `kind` and
`id` alone.

```php
use FancyChat\Facades\FancyChat;
use Illuminate\Http\Request;

FancyChat::actor(fn (Request $r): ?array => $r->user() ? ['kind' => 'human', 'id' => (string) $r->user()->getKey()] : null)
    ->access(fn (Request $r, string $threadId, string $ability): bool => /* read | write | cancel | decide */ true)
    ->backend(new MyAgentBackend())
    ->stagedWrite('send-email', new SendEmailWrite());
```

`access` must return exactly `true`; any other value refuses.

## Routes

Mounted under `fancy-chat` (configurable, or off with `fancy-chat.routes`):

| Route | Purpose |
|---|---|
| `POST threads/{id}/messages` | A human sends `{parts, supersedes?}`. Answers **201** `{messageId, seq, delivery}` (`sent`; `queued` mid-turn; `decided` when typed approval took it, with `typedDecision`, see below). |
| `GET threads/{id}/events` | SSE. Resumes from `Last-Event-ID` or `?after`, replays, then streams live with heartbeat comments until `sse.max_duration_ms`. |
| `GET threads/{id}/events/poll?after=&wait=` | Long-poll: `{events, next}`. |
| `GET threads/{id}/snapshot?at=` | 200 `{snapshot, seq, capabilities}`: `snapshot` is the projected state (fancy-chat-js `ThreadState`: `lastSeq`, `order`, `messages`, `turns`, `thinking`, `tools`, `plans`, `approvals`, `questions`, `undos`, `branches`) at `at` (default: now); `seq` is its `lastSeq`; `capabilities` as below. `410 snapshot.gone` before the retained window, `422 snapshot.at` past the end. |
| `GET threads/{id}/capabilities` | 200 `{capabilities: {steering, cancel, approvals}}`, the backend's, as booleans (all false without one). A UI hides Stop when `cancel` is false. |
| `POST threads/{id}/turns/{turnId}/retry` | A human (with `write` access) retries a failed, interrupted or cancelled turn: its ORIGINAL message parts are re-sent as a new turn, found through the turn's `messageId`, so it works after a reload. Answers **201** `{messageId, seq, delivery}` (`queued` behind a running turn). `409 turn.state` for a turn that did not fail, `404 turn.unknown`, `422 turn.no-message`. |
| `POST threads/{id}/cancel` | `{mode: stop or interrupt}`. Returns `{outcome}`: `ok`, `not-running`, `no-turn`, `unsupported`, `refused`, `uncertain`. A stop withdraws the turn's parked approvals. |
| `DELETE threads/{id}/queue/{messageId}` | The author unsends a queued message. |
| `POST approvals/{id}/decision` | `{optionId, note?, edited?}`. Human actors only. |
| `POST questions/{id}/answer` | `{values, notes?}`. Human actors only. |
| `POST undo/{actionId}/revert` | Human actors only, before the deadline. Returns `{undo, seq}`. |

A cursor older than the retention window gets a `thread.reset` event carrying
the snapshot, then the retained events, so a client always converges on the
same projection.

## The rules it enforces

- **Only a human decides.** Decision and answer endpoints take the actor from
  your resolver only; a non-human actor gets 403, and nothing in the request
  body can choose or name the actor.
- **A staged write applies only after a human decision record exists.** The
  approval is decided (`approval.decided`), then applied (`approval.applied`),
  then the parked turn resumes with the outcome.
- **Human-typed and model-written parts are untrusted.** Neither can carry an
  approval or question part, or raw ECharts options.
- **A backend cannot speak for the server or the human.** It cannot emit
  decisions, answers, deliveries or resets, nor use a non-agent actor. A refused
  event is recorded as `x-fancy-chat.refused` with its error code.
- **One actionable at a time.** An in-flight lock holds while a decision posts.
- **A held plan step releases only through its approval.** A backend's
  `plan.updated` that releases one early is refused before it is appended.

## Typed approval (opt-in per app)

Off by default, with no phrases until you set them:

```php
'typed_approval' => ['enabled' => true, 'locale' => 'en', 'phrases' => ['en' => \FancyChat\Contract\TypedApproval::ENGLISH]],
```

When the thread's one actionable HITL item is a pending approval and a human
sends exactly one text part matching a phrase (after NFKC, case, spacing and
trailing-punctuation folding; never a model), the message IS the decision. It
takes the same decide path as `POST approvals/{id}/decision` (the same human
actor, access check, in-flight lock and staged write), is recorded as the
human's message with delivery `sent`, and is never delivered to the agent. The
response adds `typedDecision: {approvalId, optionId, decision, state}`, and the
log gets `x-fancy-chat.typed-decision {messageId, approvalId, optionId,
decision}`. A typed phrase picks a one-time option only. If the decision is
refused (another decision in flight, access), the message is dropped and the
error stands. Requires ext-intl.

## Undo windows

Register a `FancyChat\Undoable` (`commit`, `revert`) per action kind with
`FancyChat::undoable($kind, $undoable)`, then, right after the action:

```php
$actionId = $chat->openUndo($threadId, 'send-email', 'Email to 3 people', 30_000);
```

The window is your choice per action (default `undo.default_ms`, capped at
`undo.max_ms`). A human reverts with `POST undo/{actionId}/revert` before the
deadline; `fancy-chat:commit-undo` (and `fancy-chat:reap`) commits the rest.
A revert and the commit each claim the window atomically, so exactly one of
them runs, once, even when they race. A throw leaves the window open.

## Edit and resend

`POST messages` with `supersedes` replaces the author's own earlier user
message. The projection records the superseded branch in `branches`.
`edit_resend.policy` decides its fate: `branch` (the default) keeps it;
`truncate` retracts its messages and withdraws its pending approvals.

## Events for the host

- **`FancyChat\Events\ChatTurnSettled`** `{threadId, turnId, outcome, reason}`, with
  `succeeded()` (outcome `completed`). Fired once per settled turn, on every path that
  settles one, and only after the surrounding transaction commits
  (`ShouldDispatchAfterCommit`). This is the hook for a "reply ready" notification;
  a park is not a settle and never fires it.
- **`FancyChat\Events\ChatTurnParking`** `{threadId, turnId, hitlIds}`. Fired
  synchronously when a turn parks, BEFORE its approvals and questions are published,
  so evidence a listener records exists before any human can act. A listener that
  throws fails the turn and nothing is published.

## Approvals become actionable when the turn parks

An `approval.requested` or `question.asked` a BACKEND emits is held, not published,
until that turn parks (its stream exhausted: for Prism Harness, after the run and the
host's recorders have persisted it). Then `ChatTurnParking` fires, the records are
published and indexed, and the park is appended, in that order. Before then the
approval is invisible and a decision on it is `404 approval.unknown`. A turn that ends
without parking never publishes what it held (`x-fancy-chat.refused`
`approval.unparked`).

**Queue-and-resume.** A decision recorded while its turn is still running (on an
approval the host appended itself, or in the instant between publishing and the park)
is kept, and the turn resumes on its own the moment it parks. A park resumes only on
decisions made during that run, so a backend that parks again with nothing new waits.

## Backends

Implement `FancyChat\Backend\AgentBackend` (`start`, `cancel`,
`capabilities`). `start(TurnInput)` yields neutral events; the server assigns
seq and thread and checks each against the contract. `NullBackend` is a
scripted backend for tests and demos.

`start(TurnInput)` gets `kind` `message`, or `resume` after a human acted. A resume
carries `approval` / `question` (the record that triggered it) and `approvals` /
`questions`: EVERY approval of that turn a human decided and every question answered,
in the order raised, so a backend parked on several sees all of them.

The host may also append events itself (`FancyChat::append`). An
`approval.requested` or `question.asked` goes through the same checks as a backend's
(record shape, option kinds, preview parts) and is stamped with its turn (the
event's `turnId`, else the thread's active turn). An id already used in ANY thread is
refused (`409 approval.duplicate`), because decisions are routed by id.

Turns run in the queued `RunTurn` job, with `$timeout` and `$tries` from
`queue.timeout` (600 s) and `queue.tries` (1: a turn that failed part way may already
have run tools, so it is not retried unless you opt in). Schedule
`php artisan fancy-chat:reap` to settle stalled turns.

## The optional nudge

With `fancy-chat.nudge.enabled`, each append broadcasts ONLY `{threadId, seq}`
on `private-fancy-chat.{threadId}`. That tells a client to fetch; it never
carries the payload, and a missed nudge costs nothing.

## Configuration

Every limit lives in `config/fancy-chat.php`: `retention.events`,
`poll.{wait_ms,max_wait_ms,interval_ms,limit}`,
`sse.{heartbeat_ms,max_duration_ms,interval_ms,retry_ms}`,
`limits.{event_bytes,part_bytes,part_depth,parts_per_message,queue_length}`,
`reaper.{timeout_ms,include_parked}`, `cancel.confirm_wait_ms`, `hitl.scope`,
`typed_approval.{enabled,locale,phrases}`, `undo.{default_ms,max_ms,batch}`,
`edit_resend.policy`, `nudge.*` and `queue.{connection,name,timeout,tries}`,
`limits.tool_output_chars`.

## Develop

```bash
composer update
vendor/bin/pest          # forced in-memory SQLite; provider keys blanked
php tests/sabotage.php   # breaks one clause at a time; the suite must notice each
php tests/lint.php
```

### The end-to-end server

`tests/e2e/serve.php` boots the package against a SQLite FILE for driving a
real client (fancy-chat-ui's transport) over HTTP. Not for production.

```bash
FANCY_CHAT_E2E_DB=/tmp/fancy-chat-e2e.sqlite php -S 127.0.0.1:8765 tests/e2e/serve.php
```

| Env | Meaning |
|---|---|
| `FANCY_CHAT_E2E_DB` | Required. The SQLite file; created and migrated on first use. |
| `FANCY_CHAT_E2E_SSE_MS` | SSE max duration per connection, default 1500 (`php -S` serves one request at a time). |
| `FANCY_CHAT_E2E_POLL_MS` | Long-poll max wait, default 1500. |
| `FANCY_CHAT_E2E_TYPED` | `1` turns typed approval on with the English phrases. |
| `FANCY_CHAT_E2E_UNDO_MS` | The undo window opened when the approval's write applies, default 30000. |

Header `X-E2E-User: <id>` is the human actor (none: 401). Every thread is
allowed. A message starts a sync turn that streams thinking, one tool call and
result, an assistant text part in three deltas, then approval `ap_<turnId>`
(options `yes` allow-once, `no` reject-once) and parks. Approving applies a
staged write that opens an undo window, then the turn resumes with "Sent.".

MIT licence.
