<?php

declare(strict_types=1);

use FancyChat\Tests\Support\ScriptedBackend;
use FancyChat\Backend\TurnInput;

/*
 * Tool results carry their output inline (fancy-chat-js `tool.result.output`):
 * the result text, or a failure's own words. The SERVER bounds it, so a tool that
 * returns megabytes cannot flood every client; a cut output is marked `truncated`,
 * and `outputRef` (the host's) points at the rest. It is plain text end to end.
 */

/** A backend whose one tool returns `$output` (or fails with it). */
function outputBackend(mixed $output, string $outcome = 'ok', array $extra = []): ScriptedBackend
{
    return new ScriptedBackend(function (TurnInput $input) use ($output, $outcome, $extra): iterable {
        yield ['type' => 'tool.called', 'runId' => 'r_'.$input->turnId, 'data' => ['toolCallId' => 'tc_'.$input->turnId, 'name' => 'search', 'args' => []]];
        yield ['type' => 'tool.result', 'runId' => 'r_'.$input->turnId, 'data' => ['toolCallId' => 'tc_'.$input->turnId, 'outcome' => $outcome, 'output' => $output] + $extra];
    });
}

function onlyTool(): array
{
    return chat()->snapshot('t_1')['tools'][0];
}

it('keeps a tool result\'s output inline, with its language', function (): void {
    chatApp();
    bindHost()->backend(outputBackend('{"deals":[7]}', 'ok', ['outputLanguage' => 'json']));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('find acme'), 'human:u_1');
    expect(onlyTool())->toMatchArray(['output' => '{"deals":[7]}', 'outputLanguage' => 'json', 'truncated' => false]);
});

it('keeps a failure\'s own words', function (): void {
    chatApp();
    bindHost()->backend(outputBackend('SMTP 550: mailbox unavailable', 'failure'));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('send it'), 'human:u_1');
    expect(onlyTool())->toMatchArray(['outcome' => 'failure', 'output' => 'SMTP 550: mailbox unavailable']);
});

it('bounds the output at the configured limit, by characters, and marks it truncated', function (): void {
    chatApp(['fancy-chat.limits.tool_output_chars' => 10]);
    bindHost()->backend(outputBackend('ééééééééééABCDEF'));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x'), 'human:u_1');
    expect(onlyTool())->toMatchArray(['output' => 'éééééééééé', 'truncated' => true]);
});

it('an output exactly at the limit is whole and not truncated', function (): void {
    chatApp(['fancy-chat.limits.tool_output_chars' => 4]);
    bindHost()->backend(outputBackend('abcd'));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x'), 'human:u_1');
    expect(onlyTool())->toMatchArray(['output' => 'abcd', 'truncated' => false]);
});

it('has a default limit, so an unconfigured host is still bounded', function (): void {
    chatApp();
    expect(config('fancy-chat.limits.tool_output_chars'))->toBe(16384);
    bindHost()->backend(outputBackend(str_repeat('x', 20000)));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x'), 'human:u_1');
    expect(mb_strlen(onlyTool()['output']))->toBe(16384)->and(onlyTool()['truncated'])->toBeTrue();
});

it('drops an output that is not text', function (): void {
    chatApp();
    bindHost()->backend(outputBackend(['html' => '<b>x</b>']));
    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('x'), 'human:u_1');
    expect(onlyTool()['output'])->toBeNull();
});
