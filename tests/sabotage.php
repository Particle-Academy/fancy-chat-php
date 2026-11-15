<?php

declare(strict_types=1);

/*
 * Break one clause at a time; the suite must notice every one. A suite written
 * alongside its implementation passes on its first run, and a check never seen
 * to fail cannot be told apart from one that cannot. Each file is restored in a
 * `finally`. Exits 1 if any mutation survives, 2 if a mutation's target text is
 * missing (the mutation list has drifted from the code).
 *
 * The fixture-pin test is skipped during the sweep (FANCY_CHAT_SABOTAGE=1), so
 * a mutation of machines.json has to be caught by BEHAVIOUR, not by its hash.
 *
 *     php tests/sabotage.php
 */

$root = dirname(__DIR__);

/** @var list<array{0: string, 1: string, 2: string, 3: string}> [name, file, search, replace] */
$mutations = [
    // The contract port.
    ['unknown major accepted', 'src/Contract/Envelope.php', '(int) $e[\'v\'] !== 1', '(int) $e[\'v\'] === 99'],
    ['seq zero accepted', 'src/Contract/Envelope.php', '! Json::isInteger($seq) || $seq < 1', '! Json::isInteger($seq) || $seq < 0'],
    ['data list accepted', 'src/Contract/Envelope.php', "if (! array_key_exists('data', \$e) || ! Json::isObject(\$e['data'])) {", "if (! array_key_exists('data', \$e)) {"],
    ['run ids unchecked', 'src/Contract/Envelope.php', "return ['ok' => false, 'error' => 'event.'.\$key];", ';'],
    ['turn settles from pending', 'contract/machines.json', '{ "from": "running", "action": "finish", "to": "settled", "outcome": "completed" },', "{ \"from\": \"running\", \"action\": \"finish\", \"to\": \"settled\", \"outcome\": \"completed\" },\n      { \"from\": \"pending\", \"action\": \"finish\", \"to\": \"settled\", \"outcome\": \"completed\" },"],
    ['approve without deciding', 'contract/machines.json', '{ "from": "deciding", "action": "approve", "to": "approved" },', "{ \"from\": \"deciding\", \"action\": \"approve\", \"to\": \"approved\" },\n      { \"from\": \"pending\", \"action\": \"approve\", \"to\": \"approved\" },"],
    ['tone from the label', 'src/Contract/Machines.php', "if (! array_key_exists(\$kind, \$kinds)) {", "if (str_starts_with(\$kind, 'approve')) {\n            return ['tone' => 'positive', 'decision' => 'approve'];\n        }\n        if (! array_key_exists(\$kind, \$kinds)) {"],
    ['an agent may decide', 'src/Contract/Hitl.php', "(\$actor['kind'] ?? null) === 'human'", "(\$actor['kind'] ?? null) !== 'system'"],
    ['empty human id decides', 'src/Contract/Hitl.php', "is_string(\$actor['id'] ?? null) && \$actor['id'] !== ''", "is_string(\$actor['id'] ?? null)"],
    ['reserved names case-sensitive', 'src/Contract/Hitl.php', '$lower = mb_strtolower($name);', '$lower = $name;'],
    ['blank note counts', 'src/Contract/Hitl.php', "is_string(\$note) && Json::jsTrim(\$note) !== ''", 'is_string($note)'],
    ['notes ignore allowNote', 'src/Contract/Hitl.php', "if ((\$question['allowNote'] ?? false) && is_string(\$note)", 'if (is_string($note)'],
    ['unoffered option accepted', 'src/Contract/Hitl.php', "return ['ok' => false, 'error' => 'question.option'];", ';'],
    ['in-flight lock ignored', 'src/Contract/Hitl.php', "if ((self::IN_FLIGHT[\$item['kind']] ?? null) === \$item['state']) {\n                return null;", "if (false) {\n                return null;"],
    ['newest actionable wins', 'src/Contract/Hitl.php', "\$item['seq'] < \$best['seq']", "\$item['seq'] > \$best['seq']"],
    ['raw echarts from agents', 'src/Contract/Parts.php', "if (\$origin !== 'server') {\n                        return self::fail('part.chart.raw');", "if (false) {\n                        return self::fail('part.chart.raw');"],
    ['agents place approvals', 'src/Contract/Parts.php', "if (\$origin !== 'server') {\n                    return self::fail('part.hitl.origin');", "if (false) {\n                    return self::fail('part.hitl.origin');"],
    ['javascript links in cards', 'src/Contract/Parts.php', "preg_match('#^https?://#i', \$a['href']) !== 1", "\$a['href'] === ''"],
    ['no depth cap', 'src/Contract/Parts.php', "if (\$depth > \$limits['maxDepth']) {", 'if (false) {'],
    ['size cap off by one', 'src/Contract/Parts.php', "Json::bytes(\$p) > \$limits['maxPartBytes']", "Json::bytes(\$p) > \$limits['maxPartBytes'] + 1"],
    ['unknown core kinds pass', 'src/Contract/Parts.php', "if (preg_match(self::EXTENSION_KIND, \$kind) !== 1) {\n                return self::fail('part.kind');", "if (false) {\n                return self::fail('part.kind');"],
    ['table TSV loses a row', 'src/Contract/Parts.php', "foreach (\$part['rows'] ?? [] as \$row) {", "foreach (array_slice(\$part['rows'] ?? [], 1) as \$row) {"],
    ['broken does not win', 'src/Contract/Status.php', "if (is_string(\$broken) && \$broken !== '') {", 'if (false) {'],
    ['cooling inclusive', 'src/Contract/Status.php', "if (\$idleFor < \$cfg['coolingMs']) {", "if (\$idleFor <= \$cfg['coolingMs']) {"],
    ['pending hitl never parks', 'src/Contract/Status.php', "if (\$turnState === 'awaiting-human' || \$pending > 0) {", "if (\$turnState === 'awaiting-human') {"],
    ['replays re-applied', 'src/Contract/Projection.php', "if (\$seq <= \$state['lastSeq']) {", "if (\$seq < \$state['lastSeq']) {"],
    ['a transition may settle', 'src/Contract/Projection.php', "if (\$i === null || \$settling !== (\$type === 'turn.settled')) {", 'if ($i === null) {'],
    ['agent decisions recorded', 'src/Contract/Projection.php', "if (\$i === null || ! self::decidedByActor(\$event, \$data['decidedBy'] ?? null) || ! is_string(\$data['decision'] ?? null)) {", "if (\$i === null || ! is_string(\$data['decision'] ?? null)) {"],
    ['decider need not be the actor', 'src/Contract/Projection.php', "&& (\$claimed['kind'] ?? null) === \$actor['kind'] && (\$claimed['id'] ?? null) === \$actor['id']", ''],
    ['agent answers recorded', 'src/Contract/Projection.php', "\$state['questions'][\$i]['state'] !== 'open' || ! self::decidedByActor(\$event, \$data['answeredBy'] ?? null)) {", "\$state['questions'][\$i]['state'] !== 'open') {"],
    ['supersede not marked', 'src/Contract/Projection.php', "\$state['messages'][\$t]['superseded'] = true;", ';'],
    ['text deltas replace', 'src/Contract/Projection.php', "(string) (\$current['text'] ?? '').\$data['text']", "\$data['text']"],

    // The server.
    ['seq chosen by the caller', 'src/Store/MemoryThreadEventStore.php', "\$event['seq'] = \$seq;", "\$event['seq'] ??= \$seq;"],
    // NOT listed, as a provably equivalent mutation on the only database the
    // hermetic suite has: replacing the `last_seq + 1` SQL increment with a
    // read-then-write inside the same transaction. SQLite takes a
    // database-level write lock at the transaction's first write (the
    // insertOrIgnore), so the read-then-write is serialised too and no test on
    // SQLite can tell them apart. The atomic increment is what makes Postgres
    // and MySQL safe (a row lock on UPDATE). Dropping the transaction itself is
    // a separate entry below, and the suite catches it.
    ['database append outside a transaction', 'src/Store/DatabaseThreadEventStore.php', "return \$this->conn()->transaction(function (ConnectionInterface \$c) use (\$threadId, \$event, \$nowMs): array {", "return (function (ConnectionInterface \$c) use (\$threadId, \$event, \$nowMs): array {"],
    ['memory claim always wins', 'src/Store/MemoryThreadEventStore.php', "if ((\$this->hitl[\$hitlId]['state'] ?? null) !== \$from) {", 'if (false) {'],
    ['database claim always wins', 'src/Store/DatabaseThreadEventStore.php', "->where('state', \$from)->update(['state' => \$to]) === 1;", "->update(['state' => \$to]) >= 0;"],
    ['prune drops events from the snapshot', 'src/Store/MemoryThreadEventStore.php', "Projection::apply(\$state, \$event);\n        }\n        \$this->checkpoints", "}\n        \$state['lastSeq'] = \$events[\$drop - 1]['seq'];\n        \$this->checkpoints"],
    ['no thread.reset for an old cursor', 'src/FancyChat.php', "if (\$checkpoint['seq'] > 0 && \$after < \$checkpoint['seq']) {", 'if (false) {'],
    ['the caller picks the thread id', 'src/FancyChat.php', "'threadId' => \$threadId,\n            'at' => \$this->iso(\$this->now()),\n            'type' => \$partial['type'] ?? null,", "'threadId' => \$partial['threadId'] ?? \$threadId,\n            'at' => \$this->iso(\$this->now()),\n            'type' => \$partial['type'] ?? null,"],
    ['event size unchecked', 'src/FancyChat.php', "if (Json::bytes(\$event) > (int) \$this->cfg('limits.event_bytes', 262144)) {", 'if (false) {'],
    ['nudge leaks the payload', 'src/FancyChat.php', "['threadId' => \$threadId, 'seq' => \$seq]", "['threadId' => \$threadId, 'seq' => \$seq, 'event' => \$this->store->after(\$threadId, \$seq - 1, 1)[0] ?? null]"],
    ['nudge on by default', 'src/FancyChat.php', "if (! \$this->cfg('nudge.enabled', false)) {", "if (\$this->cfg('nudge.enabled', true) === 'never') {"],
    ['unconfigured host allowed', 'src/FancyChat.php', "if (\$this->actorResolver === null || \$this->accessResolver === null) {\n            throw new ChatException('chat.unconfigured', 403);", "if (\$this->actorResolver === null) {\n            throw new ChatException('chat.unconfigured', 403);"],
    ['truthy access allowed', 'src/FancyChat.php', "(\$this->accessResolver)(\$request, \$threadId, \$ability) !== true", "! (\$this->accessResolver)(\$request, \$threadId, \$ability)"],
    ['agents send as humans', 'src/FancyChat.php', "\$this->authorize(\$request, \$threadId, 'write');\n        \$this->requireHuman(\$actor);\n        if (\$this->backend === null) {", "\$this->authorize(\$request, \$threadId, 'write');\n        if (\$this->backend === null) {"],
    ['human parts trusted', 'src/FancyChat.php', "\$valid = Parts::validateList(\$parts, 'agent', \$this->partLimits());", "\$valid = Parts::validateList(\$parts, 'server', \$this->partLimits());"],
    ['configured part limits ignored', 'src/FancyChat.php', "'maxParts' => (int) \$this->cfg('limits.parts_per_message', 64),", "'maxParts' => 64,"],
    ['no queue cap', 'src/FancyChat.php', "if (\$queued >= (int) \$this->cfg('limits.queue_length', 20)) {", 'if (false) {'],
    ['mid-turn message starts a second turn', 'src/FancyChat.php', "if (\$this->activeTurn(\$state) !== null) {\n            \$queued", "if (false) {\n            \$queued"],
    ['queue never drains', 'src/FancyChat.php', "if (\$current === 'settled') {\n            \$this->dequeueNext(\$threadId);", "if (false) {\n            \$this->dequeueNext(\$threadId);"],
    ['backend may emit decisions', 'src/FancyChat.php', "'approval.decided', 'approval.applied', 'approval.withdrawn', 'question.answered',", "'approval.applied', 'approval.withdrawn',"],
    ['backend may speak as a human', 'src/FancyChat.php', "(\$actor['kind'] ?? null) !== 'agent'", "(\$actor['kind'] ?? null) === 'system'"],
    ['backend parts trusted', 'src/FancyChat.php', "\$v = Json::isObject(\$message) ? Parts::validateList(\$message['parts'] ?? [], 'agent', \$this->partLimits())", "\$v = Json::isObject(\$message) ? Parts::validateList(\$message['parts'] ?? [], 'server', \$this->partLimits())"],
    ['backend exception stored', 'src/FancyChat.php', "'action' => 'fail', 'reason' => 'backend.error'", "'action' => 'fail', 'reason' => substr(\$e->getMessage(), 0, 120)"],
    ['decision from an agent', 'src/FancyChat.php', "\$actor = \$this->resolveActor(\$request);\n        \$this->requireHuman(\$actor);\n        \$threadId = \$this->store->locate(\$approvalId)", "\$actor = \$this->resolveActor(\$request);\n        \$threadId = \$this->store->locate(\$approvalId)"],
    ['decider taken from the body', 'src/FancyChat.php', "'decidedBy' => \$actor,\n                'optionId'", "'decidedBy' => \$body['decidedBy'] ?? \$actor,\n                'optionId'"],
    ['second decision allowed', 'src/FancyChat.php', "if (\$record['state'] !== 'pending') {\n            throw new ChatException('approval.state', 409);", "if (\$record['state'] === 'applied') {\n            throw new ChatException('approval.state', 409);"],
    ['unoffered option decides', 'src/FancyChat.php', "if (\$decision === null) {\n            throw new ChatException('approval.option', 422);", "if (\$option === null && false) {\n            throw new ChatException('approval.option', 422);"],
    ['in-flight claim ignored', 'src/FancyChat.php', "if (! \$this->store->claim(\$approvalId, 'pending', 'deciding')) {", "if (! \$this->store->claim(\$approvalId, 'pending', 'deciding') && false) {"],
    ['reject applies the write', 'src/FancyChat.php', "if (\$decision !== 'reject' && is_string(\$writeName)", "if (is_string(\$writeName)"],
    ['apply without a human decision', 'src/FancyChat.php', "if (\$record === null || ! in_array(\$record['state'], ['approved', 'edited'], true) || ! Hitl::canDecide(\$record['decidedBy'] ?? null)) {", 'if ($record === null) {'],
    ['parked turn never resumes', 'src/FancyChat.php', "\$this->append(\$threadId, ['type' => 'turn.transition', 'turnId' => \$turnId, 'data' => ['turnId' => \$turnId, 'action' => 'resume']]);", ';'],
    ['answerer taken from the body', 'src/FancyChat.php', "'answeredBy' => \$actor,", "'answeredBy' => \$body['answeredBy'] ?? \$actor,"],
    ['incomplete answer accepted', 'src/FancyChat.php', "\$check = Hitl::canSubmitAnswer(\$record, \$draft);\n        if (! \$check['ok']) {", "\$check = Hitl::canSubmitAnswer(\$record, \$draft);\n        if (false) {"],
    ['stop keeps the parked approval', 'src/FancyChat.php', "\$this->append(\$threadId, ['type' => 'approval.withdrawn', 'turnId' => \$turnId, 'data' => ['approvalId' => \$a['approvalId']]]);", ';'],
    ['unsupported cancel attempted', 'src/FancyChat.php', "(\$this->backend->capabilities()['cancel'] ?? false) !== true", 'false'],
    ['confirm wait ignored', 'src/FancyChat.php', "(int) \$this->cfg('cancel.confirm_wait_ms', 2000)", '2000'],
    ['interrupt settles as cancelled', 'src/FancyChat.php', "\$mode === 'stop' ? 'cancelled' : 'interrupted'", "'cancelled'"],
    ['anyone unsends', 'src/FancyChat.php', "throw new ChatException('message.author', 403);", ';'],
    ['unsend after delivery', 'src/FancyChat.php', "if (\$message['delivery'] !== 'queued') {\n            throw new ChatException('delivery.illegal', 409);", "if (false) {\n            throw new ChatException('delivery.illegal', 409);"],
    ['reaper one ms early', 'src/FancyChat.php', 'staleThreads($this->now() - $timeout)', 'staleThreads($this->now() - $timeout + 1)'],
    ['reaper kills parked turns', 'src/FancyChat.php', "(\$turn['state'] === 'awaiting-human' && ! \$includeParked)", 'false'],
    ['poll wait unclamped', 'src/FancyChat.php', "min(\$waitMs, (int) \$this->cfg('poll.max_wait_ms', 30000))", '$waitMs'],
    ['sse never heartbeats', 'src/FancyChat.php', "\$write(\": heartbeat\\n\\n\");", ';'],
    ['sse ignores Last-Event-ID', 'src/Http/ChatController.php', "\$request->headers->get('Last-Event-ID') ?? \$request->query('after')", "\$request->query('after')"],
    ['wire sends [] for an empty map', 'src/Contract/Json.php', "if ((\$key !== null && in_array(\$key, self::MAP_KEYS, true)) || \$inMapList) {", 'if (false) {'],
];

$failed = [];
$missing = [];
foreach ($mutations as [$name, $file, $search, $replace]) {
    $path = $root.'/'.$file;
    $original = (string) file_get_contents($path);
    if (! str_contains($original, $search)) {
        echo "MISSING  {$name}: pattern not found in {$file}\n";
        $missing[] = $name;

        continue;
    }
    try {
        file_put_contents($path, str_replace($search, $replace, $original));
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/pest').' --bail 2>&1';
        $env = getenv();
        $env['FANCY_CHAT_SABOTAGE'] = '1';
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0) {
            echo "SURVIVED {$name}\n";
            $failed[] = $name;
        } else {
            echo "caught   {$name}\n";
        }
    } finally {
        file_put_contents($path, $original);
    }
}

$total = count($mutations);
$caught = $total - count($failed) - count($missing);
echo "sabotage: {$total} mutations, {$caught} caught, ".count($failed).' survived, '.count($missing)." missing\n";
exit($missing !== [] ? 2 : ($failed !== [] ? 1 : 0));
