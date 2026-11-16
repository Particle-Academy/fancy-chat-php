<?php

declare(strict_types=1);

use FancyChat\Tests\Support\ScriptedBackend;
use FancyChat\Backend\TurnInput;
use FancyChat\Jobs\RunTurn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * impactium's reproducer: the owner decides INSIDE the HITL index insert, i.e.
 * the instant the approval becomes decidable, while the turn has not yet parked.
 * The decision must be accepted AND resume the turn once it parks: a second
 * RunTurn is queued and finishing it completes the turn.
 */

it('a decision made while the approval is being published resumes the turn once it parks', function (): void {
    chatApp(['fancy-chat.store' => 'database']);
    Queue::fake();
    $inputs = [];
    bindHost()->backend(new ScriptedBackend(function (TurnInput $input) use (&$inputs): iterable {
        $inputs[] = $input;
        if ($input->kind === 'resume') {
            yield ['type' => 'message.created', 'data' => ['message' => ['id' => 'done', 'role' => 'assistant', 'parts' => [['id' => 'p', 'kind' => 'text', 'format' => 'plain', 'text' => 'Stored.']]]]];

            return;
        }
        yield ['type' => 'approval.requested', 'data' => ['approval' => [
            'approvalId' => 'ap_race', 'title' => 'Store the fact?',
            'options' => [['id' => 'approve', 'kind' => 'allow-once', 'label' => 'Approve'], ['id' => 'deny', 'kind' => 'reject-once', 'label' => 'Deny']],
        ]]];
        yield ['type' => 'turn.transition', 'data' => ['action' => 'park']];
    }));

    $decided = false;
    $status = null;
    DB::listen(function ($query) use (&$decided, &$status): void {
        if (! $decided && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'fancy_chat_hitl')) {
            $decided = true;
            $approval = chat()->snapshot('t_1')['approvals'][0]['approvalId'];
            $status = http('POST', "/fancy-chat/approvals/{$approval}/decision", ['optionId' => 'approve'], 'human:u_1')['status'];
        }
    });

    http('POST', '/fancy-chat/threads/t_1/messages', textMessage('learn it'), 'human:u_1');
    Queue::pushed(RunTurn::class)->first()->handle(chat());

    expect($status)->toBe(200);
    Queue::assertPushed(RunTurn::class, 2);
    Queue::pushed(RunTurn::class)->last()->handle(chat());
    expect(chat()->snapshot('t_1')['turns'][0])->toMatchArray(['state' => 'settled', 'outcome' => 'completed'])
        ->and($inputs[1]->approval['approvalId'])->toBe('ap_race');
});
