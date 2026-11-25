<?php

declare(strict_types=1);

/*
 * Every limit is an app setting. The defaults are safe; measure your own
 * ceilings before raising them.
 */
return [
    /* Mount the routes. Off, the host mounts them itself (see routes/fancy-chat.php). */
    'routes' => true,
    'prefix' => 'fancy-chat',
    'middleware' => ['web'],

    /* `database` (durable, shared between processes) or `memory` (one process). */
    'store' => 'database',
    'connection' => null,

    /* The actor a backend's events carry when it names none. */
    'agent' => ['id' => 'agent'],

    'retention' => [
        /* Events kept per thread; older ones fold into a checkpoint. A client resuming before it gets thread.reset. */
        'events' => 10000,
    ],

    'poll' => [
        'wait_ms' => 25000,
        'max_wait_ms' => 30000,
        'interval_ms' => 250,
        'limit' => 500,
    ],

    'sse' => [
        'heartbeat_ms' => 15000,
        'max_duration_ms' => 55000,
        'interval_ms' => 250,
        'retry_ms' => 2000,
    ],

    'limits' => [
        'event_bytes' => 262144,
        'part_bytes' => 65536,
        'part_depth' => 3,
        'parts_per_message' => 64,
        'queue_length' => 20,
        /* A tool result's inline output, in characters; longer is cut and marked truncated. */
        'tool_output_chars' => 16384,
    ],

    'reaper' => [
        /* A thread with no event for this long has its unsettled turns settled as failed/stalled. */
        'timeout_ms' => 600000,
        /* Parked turns wait on a human, who may take hours. Off by default. */
        'include_parked' => false,
    ],

    'cancel' => [
        /* How long a backend may take to confirm a stop or interrupt. */
        'confirm_wait_ms' => 2000,
    ],

    /*
     * Typed approval: a human typing an exact phrase while an approval is the
     * one actionable item takes the same decide path as the button. OPT-IN per
     * app, and no phrases until you set them (FancyChat\Contract\TypedApproval::ENGLISH
     * is there to opt into). Matching is deterministic, never a model.
     */
    'typed_approval' => [
        'enabled' => false,
        'locale' => 'en',
        'phrases' => [],
    ],

    /*
     * Quick replies (CC-fancy-0065): a few short next messages under the agent's
     * reply, generated after a turn settles `completed` from the user's last
     * message and that reply ONLY, on your configured model. OFF by default and
     * switched on per mode, because each one is an extra model call you pay for.
     * Never generated for a turn that parked, failed, was refused or cancelled.
     * A generation error is logged and emits nothing; it never fails or delays
     * the turn. The output is held to plain text and to the limits below, and a
     * pick is only ever an ordinary human message.
     */
    'suggestions' => [
        'enabled' => false,
        /* The agent modes (Prism Harness modes) that get them. Empty: none. */
        'modes' => [],
        /* Suggestions kept per turn. */
        'max_count' => 3,
        /* Characters in one suggestion; a longer one is dropped, never cut. */
        'max_chars' => 80,
        /* Characters of each input (the user's message, the agent's reply) sent to the model. */
        'max_input_chars' => 4000,
        /* Output tokens the generator may spend, and seconds it may take. */
        'max_output_tokens' => 256,
        'timeout_seconds' => 15,
        /* Null: the agent's own provider and model. Set both to use a smaller one. */
        'provider' => null,
        'model' => null,
    ],

    'undo' => [
        /* The window when openUndo() is given none. The host picks one per action. */
        'default_ms' => 30000,
        /* No window is longer than this. */
        'max_ms' => 600000,
        /* Windows committed per run of fancy-chat:commit-undo (and the reaper). */
        'batch' => 200,
    ],

    'edit_resend' => [
        /* `branch` keeps the superseded rows (shown on demand); `truncate` retracts them and withdraws their pending approvals. */
        'policy' => 'branch',
    ],

    'hitl' => [
        /* The coordination scope for "one actionable item": `thread` or `app`. Read by the client. */
        'scope' => 'thread',
    ],

    /*
     * The optional Echo/Reverb nudge: after each append, broadcast ONLY
     * {threadId, seq} so a client knows to fetch. Never the payload. Off by
     * default; SSE and long-poll never need it.
     */
    'nudge' => [
        'enabled' => false,
        'connection' => null,
        'channel' => 'private-fancy-chat.{threadId}',
        'event' => 'fancy-chat.seq',
    ],

    'queue' => [
        'connection' => null,
        'name' => null,
        /* RunTurn: seconds one turn may take, and attempts (a retried turn may re-run tools). */
        'timeout' => 600,
        'tries' => 1,
    ],
];
