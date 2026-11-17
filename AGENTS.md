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
key; MCP id `fancy-ui`). This package is not yet listed there; it appears once
published. Find it then with `search-backend-packages` (stack `php`). Its browser
side is `@particle-academy/fancy-chat-js` (the wire contract) and
`@particle-academy/fancy-chat-ui` (the React surfaces).

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
  `KnowledgeBridge::available()` says whether prism-graph is installed.
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
- `tests/e2e/serve.php` is for driving a client in tests. Not for production.
