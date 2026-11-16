# AGENTS.md — fancy-chat-php

`CLAUDE.md` is a symlink to this file.

**This file describes THIS REPO'S CODE and nothing else.** Process rules live in
the envelope's `RULES.md` and must never be copied here.

## What this package is

The Laravel server of the agent chat (CC-fancy-0042), built on Prism: turns run on
Prism Harness (`src/Prism/HarnessBackend.php`), served as the fancy-chat-js contract
v1. prism-graph is optional (`src/Prism/KnowledgeBridge.php`, `available()`).

## Layout

| file | what lives there |
|---|---|
| `conformance/chat-v1.json`, `contract/machines.json` | Vendored byte-identical from fancy-chat-js. Never edit them here: change them there, copy, and re-pin both legs. |
| `src/Contract/` | The contract port: `Envelope`, `Machines` (reads machines.json at runtime), `Parts`, `Hitl`, `Status`, `Projection`, `Json` (shape tests and the wire encoder). Same checks, order and error codes as the TS source. |
| `src/Store/` | `ThreadEventStore` with Memory and Database drivers: atomic seq, checkpoints, the HITL lock index. |
| `src/FancyChat.php` | The manager: host binding, append, snapshot, poll, SSE, send, runTurn, decide, answer, cancel, unsend, reap. |
| `src/Http/ChatController.php` | Thin HTTP. Every JSON body goes through `Json::encode`. |
| `src/Jobs/RunTurn.php` | The queued turn. |
| `src/Prism/` | `HarnessBackend` (RunStep g282.1 mapping, approvals, cancel, attachments) and the optional `KnowledgeBridge` (prism-graph >=0.4). Folded in from fancy-chat-prism-php in 0.2.0, same class names. |
| `tests/Harness/` | The Harness suite, namespaced `FancyChat\Tests\Harness` (its helpers in `helpers.php`), with `Support/FakeSession` (a real Session subclass yielding real RunSteps) and the real-Harness integration tests on Prism's fake model. |
| `tests/sabotage.php`, `tests/sabotage-prism.php` | One mutation at a time; every one must turn the suite red. A new clause in `src/Prism/` gets a mutation in `sabotage-prism.php`. |
| `src/Undoable.php`, `src/Console/CommitUndoCommand.php` | Undo windows: the host's commit/revert, and the committer. |
| `tests/e2e/serve.php` | The `php -S` router for end-to-end clients (SQLite file, `X-E2E-User`). |

## Invariants (each has a test, and a sabotage entry that proves it)

1. **The fixture and machines file match fancy-chat-js byte for byte** (pinned
   in `tests/Support/Fixtures.php`, CRLF folded to LF).
2. **PHP decodes `{}` and `[]` alike.** Shape checks use `Json::isObject` /
   `Json::isList`, and anything sent to a client goes through `Json::toWire`,
   so an empty map is `{}`. A new map-shaped field must be added to
   `Json::MAP_KEYS` (or `LIST_OF_MAP_KEYS` for a list of maps), with a test.
3. **Only a human decides or answers**, with the actor from the host resolver,
   never the body. The projection refuses a decision whose event actor is not
   that same human.
4. **`apply` runs only after a human decision record exists** (approved or
   edited in the projection), and never on reject.
5. **Seq is assigned by the store**, never by a caller, inside one transaction.
6. **A backend cannot emit** `thread.*`, `turn.started`, `delivery.changed`,
   `approval.decided/applied/withdrawn` or `question.answered`, nor use a
   non-agent actor. Its parts are `agent` origin.
7. **Default deny.** No actor or access resolver means `chat.unconfigured`;
   access must be literally `true`.
8. **Built on Prism; its extras optional.** `tests/Unit/PrismDependencyTest.php`:
   prism-harness and prism are required, prism-graph only suggested (and refused at
   runtime with a clear message when absent, checked in a child process), no
   scripted backend in `src/`. Map Prism's own types; never redeclare one.
9. **The suite never touches a real database**: `TestApp` forces in-memory
   SQLite (the concurrency test uses its own temp file) and blanks provider keys.
10. **Typed approval is the decide path, not a shortcut.** `typedDecision` calls
    `decide()` with the same request, so the actor, access check, lock and staged
    write are identical; off and phraseless unless the host configures it.
11. **An undo window runs commit or revert exactly once.** Both claim `open` in
    `fancy_chat_undo`; never call the host's Undoable without winning the claim.
12. **A held plan step releases only through its approval**, checked before a
    backend's `plan.updated` is appended (`Contract\Plan`), and again by the projection.
13. **Truncate cancels what the removed branch left behind**
    (`Projection::turnsToCancelForBranch`, the fixture's `branchTurns`): a parked
    turn is settled here (nothing of it runs), a running one is cancelled through the
    backend and settled only on `ok`. Then the new message starts, not queues.
14. **A typed decision's message is `decided`**, a final delivery state, so it can
    never be delivered to the agent afterwards.
15. **Every append path checks HITL records.** `appendAs` validates an
    `approval.requested` / `question.asked` (shape, option kinds, preview parts as
    the appender's origin), stamps its turn, and refuses an id used in ANY thread:
    decisions are routed by id. `append()` (the host's) and a backend's go through it.
16. **A resume carries every decided approval and answered question of its turn**
    (`TurnInput::$approvals` / `$questions`), not only the one that triggered it.
17. **A backend's approvals and questions are actionable only once its turn parks.**
    `heldHitl` holds them; `publishHeld` fires ChatTurnParking, then publishes, then the
    park is appended. A turn that ends without parking never publishes them.
18. **A decision made during a run resumes the turn when it parks** (queue-and-resume),
    and only a decision made during THAT run (`decidedBefore`): no resume loops.
19. **ChatTurnSettled fires once per turn**, after commit, only when an append actually
    settles it.
20. **Attachments go through the host.** `HarnessBackend` resolves only a new
    message's `image`/`file` parts, through the host's resolver, with the author the
    server recorded; a withheld one is named by label, never by ref.
21. **`sourceTitle` comes from `getSource`**, one scoped call per distinct source,
    never a listing walk and never the store.

## Known limits

- The snapshot is a fold of the checkpoint plus retained events on every call.
  That is correct but O(retained events); a cached projection is a later
  optimisation.
