# AGENTS.md — fancy-chat-php

`CLAUDE.md` is a symlink to this file.

**This file describes THIS REPO'S CODE and nothing else.** Process rules live in
the envelope's `RULES.md` and must never be copied here.

## What this package is

The Laravel server twin of the neutral agent-chat contract v1 (CC-fancy-0042).
No Prism dependency.

## Layout

| file | what lives there |
|---|---|
| `conformance/chat-v1.json`, `contract/machines.json` | Vendored byte-identical from fancy-chat-js. Never edit them here: change them there, copy, and re-pin both legs. |
| `src/Contract/` | The contract port: `Envelope`, `Machines` (reads machines.json at runtime), `Parts`, `Hitl`, `Status`, `Projection`, `Json` (shape tests and the wire encoder). Same checks, order and error codes as the TS source. |
| `src/Store/` | `ThreadEventStore` with Memory and Database drivers: atomic seq, checkpoints, the HITL lock index. |
| `src/FancyChat.php` | The manager: host binding, append, snapshot, poll, SSE, send, runTurn, decide, answer, cancel, unsend, reap. |
| `src/Http/ChatController.php` | Thin HTTP. Every JSON body goes through `Json::encode`. |
| `src/Jobs/RunTurn.php` | The queued turn. |
| `tests/sabotage.php` | One mutation at a time; every one must turn the suite red. |

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
8. **No Prism.** `tests/Unit/NoPrismTest.php`, with a negative control in
   `tests/Fixtures/uses-prism`.
9. **The suite never touches a real database**: `TestApp` forces in-memory
   SQLite (the concurrency test uses its own temp file) and blanks provider keys.

## Known limits (P1)

- The snapshot is a fold of the checkpoint plus retained events on every call.
  That is correct but O(retained events); a cached projection is a later
  optimisation.
- Typed approval, the undo window, edit-and-resend branching policy and
  plan-step HITL are P2.
