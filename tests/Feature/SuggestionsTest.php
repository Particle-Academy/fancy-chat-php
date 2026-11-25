<?php

declare(strict_types=1);

use FancyChat\Backend\AgentBackend;
use FancyChat\Backend\SuggestionRequest;
use FancyChat\Backend\SuggestsReplies;
use FancyChat\Backend\TurnInput;
use FancyChat\Events\ChatTurnSettled;
use FancyChat\Jobs\GenerateSuggestions;
use FancyChat\Tests\Support\ScriptedBackend;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Bus;

/*
 * Quick replies (CC-fancy-0065): generated after a turn settles completed, opt-in
 * per mode, from the user's last message and the agent's settled reply only.
 * Generation never fails or delays the turn, and a pick is only ever an ordinary
 * human message: it can never decide an approval, answer a question or call a tool.
 */

/** A backend that also suggests replies, through a closure, recording every request. */
final class SuggestingBackend implements AgentBackend, SuggestsReplies
{
    /** @var list<SuggestionRequest> */
    public array $requests = [];

    /** @var list<string> */
    public array $log = [];

    /** @param  Closure(SuggestionRequest): mixed  $suggest */
    public function __construct(private AgentBackend $inner, private Closure $suggest, private ?string $mode = 'chat') {}

    public function start(TurnInput $input): iterable
    {
        return $this->inner->start($input);
    }

    public function cancel(string $threadId, string $turnId, string $mode, int $confirmWaitMs): string
    {
        return $this->inner->cancel($threadId, $turnId, $mode, $confirmWaitMs);
    }

    public function capabilities(): array
    {
        return $this->inner->capabilities();
    }

    public function replyMode(string $threadId): ?string
    {
        return $this->mode;
    }

    public function suggestReplies(SuggestionRequest $request): array
    {
        $this->requests[] = $request;
        $this->log[] = 'generate:'.$request->turnId;

        return ($this->suggest)($request);
    }
}

final class RecordingHandler implements ExceptionHandler
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function report(Throwable $e): void
    {
        $this->reported[] = $e;
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e): never
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void {}
}

const QR_ON = ['fancy-chat.suggestions.enabled' => true, 'fancy-chat.suggestions.modes' => ['chat']];

/** @param  list<string>|Closure(SuggestionRequest): mixed  $out */
function suggesting(array|Closure $out, ?AgentBackend $inner = null, ?string $mode = 'chat'): SuggestingBackend
{
    return new SuggestingBackend($inner ?? replyBackend('Acme is won.'), $out instanceof Closure ? $out : fn (): array => $out, $mode);
}

function suggestionEvents(string $thread = 't_1'): array
{
    return array_values(array_filter(chat()->store()->after($thread, 0, 10000), fn (array $e): bool => $e['type'] === 'suggestions.updated'));
}

it('is off by default: a completed turn generates nothing', function (): void {
    chatApp();
    $backend = suggesting(['Show me the deal']);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    expect(chat()->snapshot('t_1')['turns'][0]['outcome'])->toBe('completed')
        ->and($backend->requests)->toBe([])
        ->and(suggestionEvents())->toBe([])
        ->and(chat()->snapshot('t_1')['suggestions'])->toBeNull()
        ->and(config('fancy-chat.suggestions.enabled'))->toBeFalse()
        ->and(config('fancy-chat.suggestions.modes'))->toBe([]);
});

it('is switched on per mode: enabled, but for another mode, generates nothing', function (): void {
    chatApp(['fancy-chat.suggestions.enabled' => true, 'fancy-chat.suggestions.modes' => ['support']]);
    $backend = suggesting(['Show me the deal']);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    expect($backend->requests)->toBe([])->and(suggestionEvents())->toBe([]);
});

it('a backend that names no mode gets none', function (): void {
    chatApp(QR_ON);
    $backend = suggesting(['Show me the deal'], null, null);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    expect($backend->requests)->toBe([]);
});

it('generates from ONLY the user message and the settled reply, and scopes the list to that turn', function (): void {
    chatApp(QR_ON);
    $backend = suggesting(['Show me the deal', 'Who signed it?']);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');
    $turnId = chat()->snapshot('t_1')['turns'][0]['turnId'];

    expect($backend->requests)->toHaveCount(1);
    $request = $backend->requests[0];
    // The request carries no thread history, no tool output, no thinking: two texts and limits.
    expect(array_keys(get_object_vars($request)))->toBe(['threadId', 'turnId', 'mode', 'userMessage', 'agentReply', 'maxCount', 'maxChars'])
        ->and($request->userMessage)->toBe('how is acme?')
        ->and($request->agentReply)->toBe('Acme is won.')
        ->and($request->mode)->toBe('chat')
        ->and($request->turnId)->toBe($turnId)
        ->and([$request->maxCount, $request->maxChars])->toBe([3, 80]);

    $events = suggestionEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]['actor']['kind'])->not->toBe('human')
        ->and($events[0]['data'])->toBe(['turnId' => $turnId, 'items' => [['id' => 's1', 'text' => 'Show me the deal'], ['id' => 's2', 'text' => 'Who signed it?']]])
        ->and(chat()->snapshot('t_1')['suggestions'])->toBe(['turnId' => $turnId, 'items' => [['id' => 's1', 'text' => 'Show me the deal'], ['id' => 's2', 'text' => 'Who signed it?']]]);
});

it('queues generation as its own job only when enabled', function (bool $enabled): void {
    chatApp($enabled ? QR_ON : []);
    Bus::fake([GenerateSuggestions::class]);
    bindHost()->backend(suggesting(['Ok']));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');
    $turnId = chat()->snapshot('t_1')['turns'][0]['turnId'];

    if ($enabled) {
        Bus::assertDispatched(GenerateSuggestions::class, fn (GenerateSuggestions $job): bool => $job->threadId === 't_1' && $job->turnId === $turnId && $job->tries === 1);
    } else {
        Bus::assertNotDispatched(GenerateSuggestions::class);
    }
})->with(['enabled' => true, 'disabled' => false]);

it('generates nothing when called directly while switched off', function (): void {
    chatApp(QR_ON);
    $backend = suggesting(['Ok']);
    bindHost()->backend($backend);
    Bus::fake([GenerateSuggestions::class]);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');
    $turnId = chat()->snapshot('t_1')['turns'][0]['turnId'];
    config(['fancy-chat.suggestions.enabled' => false]);
    chat()->generateSuggestions('t_1', $turnId);

    expect($backend->requests)->toBe([])->and(suggestionEvents())->toBe([]);
});

it('a zero count limit makes no model call at all', function (): void {
    chatApp(QR_ON + ['fancy-chat.suggestions.max_count' => 0]);
    $backend = suggesting(['Ok']);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    expect($backend->requests)->toBe([])->and(suggestionEvents())->toBe([]);
});

it('a turn that starts while replies are generated makes them stale: nothing is appended for the old turn', function (): void {
    chatApp(QR_ON);
    $first = true;
    $backend = suggesting(function () use (&$first): array {
        if ($first) {
            $first = false;
            // The human sends again while the first turn's replies are being written.
            http('POST', '/fancy-chat/threads/t_1/messages', textMessage('and globex?'), 'human:u_1');
        }

        return ['Ok'];
    });
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');
    $turns = chat()->snapshot('t_1')['turns'];

    expect(array_map(fn (array $e): string => $e['data']['turnId'], suggestionEvents()))->toBe([$turns[1]['turnId']])
        ->and(chat()->snapshot('t_1')['illegal'])->toBe(0);
});

it('cuts each input to the configured length before it reaches the generator', function (): void {
    chatApp(QR_ON + ['fancy-chat.suggestions.max_input_chars' => 5]);
    $backend = suggesting(['Ok']);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    expect($backend->requests[0]->userMessage)->toBe('how i')
        ->and($backend->requests[0]->agentReply)->toBe('Acme ');
});

it('is skipped when the turn parked', function (): void {
    chatApp(QR_ON);
    $inputs = [];
    $backend = suggesting(['Show me the deal'], approvalBackend($inputs));
    bindHost()->backend($backend)->stagedWrite('send-email', new CountingWrite);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('email the team'), 'human:u_1');

    expect(chat()->snapshot('t_1')['turns'][0]['state'])->toBe('awaiting-human')
        ->and($backend->requests)->toBe([])
        ->and(suggestionEvents())->toBe([]);
});

it('is skipped when the turn failed or was refused', function (string $action): void {
    chatApp(QR_ON);
    $backend = suggesting(['Show me the deal'], new ScriptedBackend(function () use ($action): iterable {
        yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'a1', 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Partial']]]]];
        yield ['type' => 'turn.settled', 'data' => ['action' => $action, 'reason' => 'x']];
    }));
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('go'), 'human:u_1');

    expect(chat()->snapshot('t_1')['turns'][0]['state'])->toBe('settled')
        ->and(chat()->snapshot('t_1')['turns'][0]['outcome'])->not->toBe('completed')
        ->and($backend->requests)->toBe([]);
})->with(['fail', 'refuse']);

it('is skipped when the backend threw and the server failed the turn', function (): void {
    chatApp(QR_ON);
    $backend = suggesting(['Show me the deal'], new ScriptedBackend(function (): iterable {
        yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'a1', 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Partial']]]]];
        throw new RuntimeException('provider down');
    }));
    app()->instance(ExceptionHandler::class, new RecordingHandler);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('go'), 'human:u_1');

    expect(chat()->snapshot('t_1')['turns'][0]['outcome'])->toBe('failed')->and($backend->requests)->toBe([]);
});

it('a generation error never fails the turn: it is logged and emits nothing', function (): void {
    chatApp(QR_ON);
    $handler = new RecordingHandler;
    app()->instance(ExceptionHandler::class, $handler);
    $backend = suggesting(function (): array {
        throw new RuntimeException('model said: secret provider text');
    });
    bindHost()->backend($backend);
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    expect($r['status'])->toBeLessThan(300)
        ->and(chat()->snapshot('t_1')['turns'][0])->toMatchArray(['state' => 'settled', 'outcome' => 'completed'])
        ->and($backend->requests)->toHaveCount(1)
        ->and(suggestionEvents())->toBe([])
        ->and($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0]->getMessage())->toBe('model said: secret provider text');
    // Nothing of the error reaches the thread.
    expect(json_encode(chat()->store()->after('t_1', 0, 10000)))->not->toContain('secret provider text');
});

it('never delays the turn: the turn settles and announces before generation starts', function (): void {
    chatApp(QR_ON);
    $order = [];
    app('events')->listen(ChatTurnSettled::class, function (ChatTurnSettled $e) use (&$order): void {
        $order[] = 'settled:'.$e->turnId;
    });
    $backend = suggesting(function (SuggestionRequest $r) use (&$order): array {
        $order[] = 'generate:'.$r->turnId;
        // The turn is already settled in the log when the generator runs.
        expect(chat()->snapshot('t_1')['turns'][0]['state'])->toBe('settled');

        return ['Ok'];
    });
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');
    $turnId = chat()->snapshot('t_1')['turns'][0]['turnId'];

    expect($order)->toBe(['settled:'.$turnId, 'generate:'.$turnId]);
});

it('never delays the next queued turn, and never offers stale replies for an older turn', function (): void {
    chatApp(QR_ON);
    $sent = false;
    $inner = new ScriptedBackend(function (TurnInput $input) use (&$sent): iterable {
        if (! $sent) {
            // A second message arrives mid-turn and queues behind this one.
            $sent = true;
            http('POST', '/fancy-chat/threads/t_1/messages', textMessage('and globex?'), 'human:u_1');
        }
        yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'a_'.$input->turnId, 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Answer '.$input->turnId]]]]];
    });
    $backend = suggesting(['Ok'], $inner);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');
    $turns = chat()->snapshot('t_1')['turns'];

    expect($turns)->toHaveCount(2)
        ->and($turns[1]['outcome'])->toBe('completed')
        // Only the newest turn got replies; the first turn's would be stale by the time it ran.
        ->and(array_map(fn (SuggestionRequest $r): string => $r->turnId, $backend->requests))->toBe([$turns[1]['turnId']])
        ->and(chat()->snapshot('t_1')['suggestions']['turnId'])->toBe($turns[1]['turnId']);
});

it('bounds the model output: plain text, count and length from config', function (): void {
    chatApp(QR_ON + ['fancy-chat.suggestions.max_count' => 2, 'fancy-chat.suggestions.max_chars' => 12]);
    $backend = suggesting(["Tell me\nmore", 'x'.str_repeat('y', 20), ['tool' => 'send_email'], 'Next', 'Third one']);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    expect(chat()->snapshot('t_1')['suggestions']['items'])->toBe([['id' => 's1', 'text' => 'Tell me more'], ['id' => 's2', 'text' => 'Next']])
        ->and([$backend->requests[0]->maxCount, $backend->requests[0]->maxChars])->toBe([2, 12]);
});

it('emits nothing when nothing usable is left', function (): void {
    chatApp(QR_ON);
    $backend = suggesting(['', '   ', str_repeat('z', 200)]);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    expect($backend->requests)->toHaveCount(1)->and(suggestionEvents())->toBe([]);
});

it('a new turn clears the replies', function (): void {
    chatApp(QR_ON);
    $calls = 0;
    $backend = suggesting(function () use (&$calls): array {
        $calls++;

        return $calls === 1 ? ['Show me the deal'] : [];
    });
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');
    expect(chat()->snapshot('t_1')['suggestions'])->not->toBeNull();
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('Show me the deal'), 'human:u_1');

    expect(chat()->snapshot('t_1')['suggestions'])->toBeNull();
});

it('a backend can never emit suggestions itself; only the server generator does', function (): void {
    chatApp(QR_ON);
    $backend = suggesting([], new ScriptedBackend(function (TurnInput $input): iterable {
        yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'a1', 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Hi']]]]];
        yield ['type' => 'suggestions.updated', 'data' => ['turnId' => $input->turnId, 'items' => [['id' => 's1', 'text' => 'approve']]]];
    }));
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('hi'), 'human:u_1');

    $refused = array_values(array_filter(chat()->store()->after('t_1', 0, 10000), fn (array $e): bool => $e['type'] === 'x-fancy-chat.refused'));
    expect(suggestionEvents())->toBe([])
        ->and($refused[0]['data'])->toBe(['type' => 'suggestions.updated', 'error' => 'backend.type']);
});

/*
 * A pick only fills the composer or sends an ordinary human message.
 */

it('a pick can never decide an approval: typed-approval phrases are never offered', function (): void {
    chatApp(QR_ON + ['fancy-chat.typed_approval' => ['enabled' => true, 'locale' => 'en', 'phrases' => ['en' => ['approve' => ['ship it'], 'reject' => ['scrap it']]]]]);
    $backend = suggesting(['Approve.', 'yes, approve', 'Ship it!', 'SCRAP IT', 'Reject', 'Show me the deal']);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');

    // The English phrases are refused whether or not the host opted into them, and so is every configured phrase.
    expect(chat()->snapshot('t_1')['suggestions']['items'])->toBe([['id' => 's1', 'text' => 'Show me the deal']]);
});

it('a pick can never decide an approval: sent as a message, it is a message and the approval stays pending', function (): void {
    chatApp(QR_ON);
    $inputs = [];
    [$approvalId] = parkedApproval($inputs);
    // The human sends a suggestion's text as an ordinary message while the approval waits.
    $r = http('POST', '/fancy-chat/threads/t_1/messages', textMessage('Show me the deal'), 'human:u_1');
    $state = chat()->snapshot('t_1');
    $message = $state['messages'][count($state['messages']) - 1];

    expect($r['status'])->toBeLessThan(300)
        ->and($state['approvals'][0])->toMatchArray(['approvalId' => $approvalId, 'state' => 'pending'])
        ->and($state['approvals'][0]['decision'] ?? null)->toBeNull()
        ->and(array_filter(chat()->store()->after('t_1', 0, 10000), fn (array $e): bool => $e['type'] === 'approval.decided'))->toBe([])
        ->and($message['author'])->toBe(['kind' => 'human', 'id' => 'u_1'])
        ->and($message['parts'][0]['text'])->toBe('Show me the deal')
        ->and($message['delivery'])->not->toBe('decided');
});

it('a pick can never answer a question: the question stays open', function (): void {
    chatApp(QR_ON);
    $backend = suggesting(['Now'], new ScriptedBackend(function (TurnInput $input): iterable {
        yield ['type' => 'question.asked', 'data' => ['question' => ['questionId' => 'q_'.$input->turnId, 'prompt' => 'When?', 'fields' => [['id' => 'when', 'type' => 'single', 'options' => [['id' => 'now', 'label' => 'Now']], 'required' => true]], 'allowNote' => false]]];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('ship it'), 'human:u_1');
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('Now'), 'human:u_1');
    $state = chat()->snapshot('t_1');

    expect($state['questions'][0]['state'])->toBe('open')
        ->and($state['questions'][0]['answeredBy'] ?? null)->toBeNull()
        ->and(array_filter(chat()->store()->after('t_1', 0, 10000), fn (array $e): bool => $e['type'] === 'question.answered'))->toBe([]);
});

it('a pick can never call a tool: suggestions are text in the log, never a tool call', function (): void {
    chatApp(QR_ON);
    $backend = suggesting(['{"tool":"send_email","args":{"to":"all"}}', 'send_email(to=all)']);
    bindHost()->backend($backend);
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('how is acme?'), 'human:u_1');
    $tools = array_values(array_filter(chat()->store()->after('t_1', 0, 10000), fn (array $e): bool => $e['type'] === 'tool.called'));

    // The only tool call is the agent's own, from its turn; the suggestions are inert strings.
    expect(array_map(fn (array $e): string => $e['data']['name'], $tools))->toBe(['search'])
        ->and(array_column(chat()->snapshot('t_1')['suggestions']['items'], 'text'))->toBe(['{"tool":"send_email","args":{"to":"all"}}', 'send_email(to=all)']);
});
