# Using particle-academy/fancy-chat-php — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-chat-php
php artisan migrate
```

Laravel 13, PHP 8.4. `particle-academy/prism-harness` and `particle-academy/prism`
come with it. The knowledge explorer is optional:
`composer require particle-academy/prism-graph:^0.4`.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package is listed there: find it with
`search-backend-packages` (stack `php`). Its browser
side is `@particle-academy/fancy-chat-js` (the wire contract) and
`@particle-academy/fancy-chat-ui` (the React surfaces):
`npm i @particle-academy/fancy-chat-js @particle-academy/fancy-chat-ui`.

## API essentials

- **Bind the host first.** `FancyChat::actor(...)`, `->access(...)`,
  `->backend(...)`, `->stagedWrite(name, write)` on the `FancyChat\Facades\FancyChat`
  facade. Until an actor and access resolver are bound, every route answers
  `403 chat.unconfigured`.
- **The backend** is `FancyChat\Prism\HarnessBackend`, given a `session:` resolver
  returning the Harness `Session` for a thread. Optional arguments: `prompt`,
  `present`, `sessionClass`, `attachments`. The seam is
  `FancyChat\Backend\AgentBackend` (`start`, `cancel`, `capabilities`).
- **Routes** mount under `fancy-chat` (configurable, or off with
  `fancy-chat.routes`): messages, SSE events, long-poll, snapshot, capabilities,
  retry, cancel, unsend, approval decision, question answer, undo revert. The full
  table is in the README.
- **Knowledge (optional):** `KnowledgeBridge::routes(new KnowledgeBridge(scope: ...), prefix, middleware)`;
  `KnowledgeBridge::available()` says whether prism-graph is installed. The routes are
  `KnowledgeController` actions that resolve `KnowledgeBridge` from the container, so
  `route:cache` works once the bridge is bound in a service provider; then mount with
  `KnowledgeBridge::routes(prefix: ..., middleware: [...])` and no bridge.
- **Quick replies (optional, off by default):** a few short next messages under the
  agent's reply, generated after a turn settles `completed`, on your configured
  model. Switch them on per Harness mode:

  ```php
  // config/fancy-chat.php
  'suggestions' => [
      'enabled' => true,
      'modes' => ['chat'],      // the Harness modes that get them; [] = none
      'max_count' => 3,         // suggestions kept per turn
      'max_chars' => 80,        // a longer one is dropped, never cut
      'max_input_chars' => 4000,// each input sent to the model is cut to this
      'max_output_tokens' => 256,
      'timeout_seconds' => 15,
      'provider' => null,       // null: the agent's own provider and model;
      'model' => null,          // set both to use a smaller model
  ],
  ```

  `HarnessBackend` implements `FancyChat\Backend\SuggestsReplies`
  (`replyMode(threadId)`, `suggestReplies(SuggestionRequest)`); a wrapping backend
  can implement it too. The server appends `suggestions.updated` and the snapshot
  carries `suggestions` (`{ turnId, items: [{ id, text }] } | null`). Each is one
  more model call per settled turn, which you pay for.
- **Every limit** lives in `config/fancy-chat.php`.

## Traps

- **`access` must return exactly `true`.** Any other value refuses.
- **No backend is bound by default:** sending answers `503 chat.backend`. The
  package ships no scripted backend.
- **Only a human decides or answers.** The actor comes from the host resolver,
  never the request body; a non-human actor gets 403.
- **A staged write applies only after a human decision record exists.**
- **Typed approval is off by default** and has no phrases until the app sets
  `typed_approval.phrases`.
- `KnowledgeBridge::routes()` throws a `LogicException` when prism-graph is not
  installed.
- **With `php artisan route:cache`, bind `KnowledgeBridge` in a service provider.**
  A cached app never runs its routes file, so `routes(new KnowledgeBridge(...))`
  binds nothing there, and every knowledge request throws a `LogicException` saying
  the bridge is not bound. Without route caching, nothing changes.
- **One bridge per app.** Every knowledge route serves the bound bridge, so mounting
  a second, different `KnowledgeBridge` throws instead of serving the first one's scope.
- **Quick replies never act.** The generator gets ONLY the user's message that
  started the turn and the agent's last reply in it, as a delimited data block the
  model is told never to obey. Output is held to plain text, the count and length
  limits, and never a typed-approval phrase. A pick is an ordinary human message
  (or a composer fill): it cannot decide an approval, answer a question or call a
  tool. A backend cannot emit `suggestions.updated` itself.
- **Quick replies never touch the turn.** They run as their own queued job
  (`FancyChat\Jobs\GenerateSuggestions`) after the turn settled and the next queued
  turn was dispatched; a turn that parked, failed, was refused or cancelled gets
  none; a generation error goes to your exception handler and appends nothing.
- `tests/e2e/serve.php` is for driving a client in tests. Not for production.
