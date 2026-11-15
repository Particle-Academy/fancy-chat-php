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

Laravel 13, PHP 8.4.

## Bind the host

Nothing works until the host binds an actor resolver and an access resolver:
every route answers `403 chat.unconfigured` until then. No user model is
assumed; an actor is `{kind, id}` from your own resolver.

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
| `POST threads/{id}/messages` | A human sends `{parts, supersedes?}`. Returns `{messageId, seq, delivery}`. Mid-turn it queues. |
| `GET threads/{id}/events` | SSE. Resumes from `Last-Event-ID` or `?after`, replays, then streams live with heartbeat comments until `sse.max_duration_ms`. |
| `GET threads/{id}/events/poll?after=&wait=` | Long-poll: `{events, next}`. |
| `GET threads/{id}/snapshot?at=` | The projected thread at a seq. |
| `POST threads/{id}/cancel` | `{mode: stop or interrupt}`. Returns `{outcome}`: `ok`, `not-running`, `no-turn`, `unsupported`, `refused`, `uncertain`. A stop withdraws the turn's parked approvals. |
| `DELETE threads/{id}/queue/{messageId}` | The author unsends a queued message. |
| `POST approvals/{id}/decision` | `{optionId, note?, edited?}`. Human actors only. |
| `POST questions/{id}/answer` | `{values, notes?}`. Human actors only. |

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

## Backends

Implement `FancyChat\Backend\AgentBackend` (`start`, `cancel`,
`capabilities`). `start(TurnInput)` yields neutral events; the server assigns
seq and thread and checks each against the contract. `NullBackend` is a
scripted backend for tests and demos.

Turns run in the queued `RunTurn` job. Schedule `php artisan fancy-chat:reap`
to settle stalled turns.

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
`nudge.*` and `queue.*`.

## Develop

```bash
composer update
vendor/bin/pest          # forced in-memory SQLite; provider keys blanked
php tests/sabotage.php   # breaks one clause at a time; the suite must notice each
php tests/lint.php
```

MIT licence.
