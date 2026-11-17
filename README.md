# particle-academy/fancy-chat-php

The Laravel server for the Fancy agent chat (council change CC-fancy-0042), built
on the Prism ecosystem. Each turn runs on a **Prism Harness** session
(`FancyChat\Prism\HarnessBackend`: Harness's `g282.1` RunSteps, streamed approvals,
attachments), and the server keeps it as an append-only thread log with an atomic, gapless per-thread
`seq`, SSE plus long-poll with resumable replay, the turn machine, staged writes,
and decision endpoints that accept **human actors only**.

It implements `@particle-academy/fancy-chat-js`, the one wire contract every
server implements (a Node twin on `@particle-academy/prism-harness` is next). Both pass the same
conformance fixture (`conformance/chat-v1.json`) to the same output and read the
same transition table (`contract/machines.json`); both files are vendored
byte-identical and pinned by SHA-256 in both repositories.

**Prism is a dependency; its extras are optional.** `particle-academy/prism-harness`
and `particle-academy/prism` are required. prism-graph (the knowledge explorer) is
suggested and checked at runtime; nothing uses prism-memory yet. 0.2.0 absorbed
`particle-academy/fancy-chat-prism-php` under the same class names (see the CHANGELOG).

Design: `.ai/plans/agent-chat-on-prism.md` in the Fancy envelope.

<!-- fancy-agents-note:start -->
> **For agents.** This package is listed on the Fancy MCP catalog server at
> `https://ui.particle.academy/mcp` (streamable HTTP, no API key; MCP id `fancy-ui`),
> with install commands and docs, alongside the rest of the kit. How an agent
> installs and uses this package is in [AGENTS.md](https://github.com/Particle-Academy/fancy-chat-php/blob/main/AGENTS.md).
<!-- fancy-agents-note:end -->

## Install

```bash
composer require particle-academy/fancy-chat-php
php artisan migrate
```

Prism Harness and Prism come with it from Packagist.

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

## Prism Harness, the backend

Bind `FancyChat\Prism\HarnessBackend` with `FancyChat::backend()`. Nothing is bound
by default: without a backend, sending answers `503 chat.backend`. The seam it plugs
into is `FancyChat\Backend\AgentBackend` (`start`, `cancel`, `capabilities`); wrap
`HarnessBackend` with it when a host needs to route some threads elsewhere (the
suite and the lab script turns through it). The package ships no scripted backend.

### What it maps

It reads Harness's tagged step stream, `Session::streamSteps()` (and
`Session::streamDecision()` on resume), contract version **`g282.1`**:

| Harness | Chat event |
|---|---|
| `thinking` steps | `thinking.started`, `thinking.delta` (`text`), `thinking.completed`. A block closes at the next non-thinking step, so a run may hold several, in order. |
| `text` steps | `message.created` (an assistant message), `part.started` (a markdown text part), `part.delta` per delta, `part.completed` when the block closes. Text after a tool starts a new message, so it renders after the tool. |
| `tool_call` | `tool.called` `{toolCallId, name, args, startedAt}` |
| `tool_result` | `tool.result` `{toolCallId, outcome, durationMs, durationExact, output, errorCode?}`. `outcome` is `refusal` when Harness says `refused`, else `ok` or `failure`. `output` is the result, or `error.message` (what the model was given). `durationExact` is `false` unless Harness says the duration is exact. |
| `approval_request` | `approval.requested`, built from the tool call it gates (title, options, the arguments as a JSON preview), then the turn parks. |
| `decision_recorded` | `x-fancy-chat-prism.decision-recorded` `{approvalId, verdict, reason, actor}` |
| `error` | the turn fails, reason `harness.error: <code>` (the code only; Harness never forwards the provider's message) |
| `step_start`, `step_finish` | boundaries: they close any open thinking or text block |
| `RunStarted` / `RunFinished` / `RunFailed` | `run.started` / `run.finished` / `run.failed`, with `runId`, `parentRunId` and `rootRunId`. The ROOT run's end also settles the turn (below). |
| `RunResumed` | `x-fancy-chat-prism.run-resumed` (a resumed run is not a second start) |

Every mapped event carries the run lineage on the envelope and the step's own
`{runId, turnId, seq, at}` in `data.harness`, so Harness's ordering survives into
the chat log.

### How a run's end settles the turn

The root run's finish reason (Prism `FinishReason`, carried by Harness's `RunFinished`)
decides the turn. A sub-run's end never does.

| Finish | Turn |
|---|---|
| `stop` | completed |
| `length` | failed, `output_limit` (the partial text stays) |
| `content-filter` | failed, `content_filter` |
| `refusal` | refused, `refusal` |
| `error` | failed, `model_error` |
| `unknown` / `other` / anything not listed | failed, `finish_unknown` / `finish_other` / `finish_unknown` |
| `tool-calls`, `pause` | parks when the run raised approvals; otherwise failed, `step_limit` / `finish_pause` |
| `RunFailed` | failed, `model_error` (error), `finish_unknown` (unknown), `budget`, or `run_failed` |

Approvals are published when the turn parks, after the stream is exhausted, so
Harness and the host's recorders (prism-graph's `TurnRecorder` included) have
persisted the run before any human can act. A decision that still lands before the
park resumes the turn once it parks (fancy-chat-php's queue-and-resume).

### The version gate

A step whose `contractVersion` is not `g282.1`, or that has none, fails the turn
loudly with reason `harness.contract-version: got "<version>", this adapter
reads "g282.1"`. Nothing from that step, or after it, is rendered. An unknown
step type within the version fails the same way (`harness.step-type: <type>`).
The shapes are agreed between Prism and Fancy and any change bumps the version,
so a step this adapter does not know is a step it must not guess at.

### Wire it

In a service provider, bind the host as above, and hand it `HarnessBackend` with a
resolver for the Harness session that serves each thread:

```php
use FancyChat\Facades\FancyChat;
use FancyChat\Prism\HarnessBackend;
use Prism\Harness\Facades\PrismHarness;
use Prism\Harness\Sessions\Session;

FancyChat::actor(fn ($request) => $request->user() ? ['kind' => 'human', 'id' => (string) $request->user()->getKey()] : null)
    ->access(fn ($request, string $threadId, string $ability): bool => /* your policy */ true)
    ->backend(new HarnessBackend(
        session: fn (string $threadId): Session => PrismHarness::session(Thread::findOrFail($threadId)->owner, 'chat-'.$threadId),
    ));
```

Optional arguments:

- `prompt: fn (TurnInput $input): string`: the prompt for a new turn. The default
  joins the message's text parts.
- `present: fn (array $call): array`: the approval's `title`, `summary` and
  `options`, given `{approvalId, toolCallId, name, args}`. The default is English
  ("Allow send_email?", Allow / Deny, one-time options only); pass your own to
  translate it.
- `sessionClass`: the class your resolver returns, used to decide whether cancel
  is offered (below).
- `attachments: fn (array $part, TurnInput $turn, ?array $author): Media|string|null`:
  the host's attachment resolver (below).

#### Attachments

A human message's `image` and `file` parts are the turn's attachments. Your
resolver is asked about each one, with `$author` = the actor the SERVER recorded for
the message (never a request value), so authorization and storage stay yours:

```php
use FancyChat\Backend\TurnInput;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;

new HarnessBackend(
    session: fn (string $threadId) => /* as above */,
    attachments: function (array $part, TurnInput $turn, ?array $author) {
        $upload = Upload::where('ref', $part['ref'])->first();
        if ($upload === null || $author === null || $upload->user_id !== $author['id']) {
            return null; // withheld: not this person's file
        }
        if ($part['kind'] === 'image') {
            return Image::fromRawContent($upload->bytes(), $upload->mime);
        }

        return $upload->mime === 'application/pdf'
            ? Document::fromRawContent($upload->bytes(), 'application/pdf')
            : "stored as upload {$upload->id}; read it with the open_upload tool";
    },
);
```

- **Prism media** (`Image`, `Document`, `Audio`, `Video`, built from bytes or a
  provider file id) goes to `Session::streamSteps()` as `additionalContent`, checked
  first by Harness's own `TurnAttachments::admit`. Media built from a URL or a path is
  refused by Harness, and the turn fails as `harness.attachment: attachment_by_reference`
  with nothing sent.
- **A string** is your pointer text for the model (an id, a tool to read it with).
- **`null`** withholds it: nothing is sent, the prompt says `[Attachment withheld:
  <name>]` (never the ref), and the log gets `x-fancy-chat-prism.attachment-withheld`.
- Anything else fails the turn as `harness.attachment: resolver_result`.
- **Every attachment gets one line in the prompt** (`[Attached image: chart.png]`,
  `[Attached file: q3.pdf (application/pdf, 2048 bytes)]`), so an image-only message
  still has the prompt Harness requires. Labels are one line, bracket-free, at most
  200 characters.
- **Without a resolver**, every attachment goes in as a metadata pointer with its ref
  (`[Attached file: q3.pdf (...), ref: file_1]`) and nothing is read.
- Only a new message's attachments are resolved; a resume never asks again.

#### Approvals

When Harness parks on approvals, each becomes a chat approval record and the
turn parks. A human decides through `fancy-chat-php`'s decision endpoint (human
actors only). When the turn resumes, every Harness approval of that turn goes
back to `Session::streamDecision()` together, because Harness denies by default
any parked call it has no answer for. `approve` is approved; `reject` (and
`edit`, which Harness cannot apply) is denied, with the human's note as the
reason. The `RunContext` actor is the decider the chat server recorded, as
`kind:id`. Approvals this adapter did not raise are never sent to Harness.

#### Cancel

`capabilities()['cancel']` is true only when the session class has a
`cancel(string $runId)` method. Harness 0.26 does not have one yet (the G-285
durable cancel), so with 0.26 cancel reports `unsupported`. Once it ships,
the adapter cancels the session's current run and passes Harness's outcome
through (`ok`, `not-running`, `no-turn`, `unsupported`, `refused`); anything
else, or an exception, is `uncertain`. Both `stop` and `interrupt` map to the
same Harness cancel.

### Knowledge (optional)

`FancyChat\Prism\KnowledgeBridge` serves fancy-chat-ui's `./knowledge` explorer from
prism-graph. prism-graph is OPTIONAL: `composer require particle-academy/prism-graph:^0.4`
to use it. `KnowledgeBridge::available()` says whether it is installed, and
`KnowledgeBridge::routes()` throws a `LogicException` naming the package when it is not. The host binds the scope from its own authentication; the client
never sends one.

```php
use FancyChat\Prism\KnowledgeBridge;
use Prism\Graph\Graph;
use Prism\Graph\Scope;

KnowledgeBridge::routes(new KnowledgeBridge(
    scope: fn (Request $r) => $r->user() ? app(Graph::class)->scope(new Scope('acme', (string) $r->user()->getKey())) : null,
    // Optional. Without it there are no accept/reject routes (a read-only explorer).
    decider: fn (Request $r) => $r->user() ? ['kind' => 'human', 'id' => (string) $r->user()->getKey()] : null,
    onDecision: fn (string $factId, string $decision, array $actor) => audit($factId, $decision, $actor),
), 'fancy-chat/knowledge', ['web', 'auth']);
```

| Route (GET unless noted) | Explorer method | Answers |
|---|---|---|
| `search?q=&limit=` | `search` | g282.1 chunk listing; each hit hydrated to a full chunk item (uri, sha256, writer, recordedAt, position) plus `score` and `method` |
| `sources?cursor=&limit=` | `listSources` | prism-graph's own g282.1 listing |
| `sources/{sourceId}/chunks?cursor=&limit=` | `listChunks` | prism-graph's own g282.1 listing |
| `chunks?ids[]=` | `getChunks` | g282.1 chunk listing, hydrated (at most 200 ids) |
| `facts?subject=&unaccepted=1` | `facts` | g282.1 listing of facts (`status` as a string) |
| `facts/timeline?subject=` | `factTimeline` | g282.1 listing of every version |
| `facts/all?subject=&cursor=&limit=` | `listFacts` | prism-graph's g282.2 listing once it ships; 501 until then |
| `ingest/jobs`, `ingest/worker` | `ingestJobs`, `workerStatus` | passed through once prism-graph ships them; 501 until then |
| POST `facts/{id}/accept`, `facts/{id}/reject` | the explorer's `onAccept` / `onReject` | only with `decider`; a non-human actor is 403, and the body is never read. Reject retracts. |

- **Hydration reads the store's own listing** (`ScopedGraph::listChunks`, page by page,
  at most `hydratePages`, default 20 per source), never a parallel copy. A chunk the
  listing no longer holds (retired since it was cited) comes back with what the chunk
  carries, `retired: true` and empty provenance strings, never invented ones.
- **`sourceTitle`.** Every chunk served (search hits, chunks by id, a source's chunks)
  carries its source's title, from ONE scoped `ScopedGraph::getSource()` per distinct
  source (prism-graph 0.4). It may be a URI and is meant to be shown as-is. A source the
  scope cannot see, or a store without source lookup, leaves it off; a read never
  fails for a label.
- **No scope, 403.** A bad cursor, limit, id list or subject is 422 (`knowledge.request`),
  and the message is never echoed.
- Every number is a constructor argument: `searchMax` (50), `hydratePages` (20),
  `maxIds` (200).

### The seam


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
php tests/sabotage.php        # breaks one clause at a time; the suite must notice each
php tests/sabotage-prism.php  # the same for src/Prism/ (Harness mapping, attachments, knowledge)
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
