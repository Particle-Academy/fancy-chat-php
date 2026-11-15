<?php

declare(strict_types=1);

namespace FancyChat\Store;

use FancyChat\Contract\Json;
use FancyChat\Contract\Projection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * The durable store. The seq is assigned inside one transaction that first
 * increments the thread row's `last_seq` (a write lock on that row, or on the
 * database for SQLite) and then inserts the event; `(thread_id, seq)` is
 * unique, so two writers can never share a seq and none can skip one.
 */
final class DatabaseThreadEventStore implements ThreadEventStore
{
    public function __construct(private DatabaseManager $db, private ?string $connection = null) {}

    private function conn(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }

    public function append(string $threadId, array $event, int $nowMs): array
    {
        return $this->conn()->transaction(function (ConnectionInterface $c) use ($threadId, $event, $nowMs): array {
            $c->table('fancy_chat_threads')->insertOrIgnore(['thread_id' => $threadId, 'last_seq' => 0, 'checkpoint_seq' => 0, 'touched_at' => $nowMs]);
            $c->table('fancy_chat_threads')->where('thread_id', $threadId)->update([
                'last_seq' => $c->raw('last_seq + 1'),
                'touched_at' => $nowMs,
            ]);
            $seq = (int) $c->table('fancy_chat_threads')->where('thread_id', $threadId)->value('last_seq');
            $event['seq'] = $seq;
            $c->table('fancy_chat_events')->insert([
                'thread_id' => $threadId,
                'seq' => $seq,
                'type' => $event['type'],
                'payload' => Json::encode($event),
            ]);

            return $event;
        }, 5);
    }

    public function after(string $threadId, int $after, int $limit): array
    {
        return $this->conn()->table('fancy_chat_events')
            ->where('thread_id', $threadId)
            ->where('seq', '>', $after)
            ->orderBy('seq')
            ->limit($limit)
            ->pluck('payload')
            ->map(fn (string $payload): array => json_decode($payload, true, 512, JSON_THROW_ON_ERROR))
            ->all();
    }

    public function lastSeq(string $threadId): int
    {
        return (int) ($this->conn()->table('fancy_chat_threads')->where('thread_id', $threadId)->value('last_seq') ?? 0);
    }

    public function checkpoint(string $threadId): array
    {
        $row = $this->conn()->table('fancy_chat_threads')->where('thread_id', $threadId)->first(['checkpoint_seq', 'checkpoint']);
        if ($row === null || $row->checkpoint === null) {
            return ['seq' => 0, 'state' => Projection::emptyState()];
        }

        return ['seq' => (int) $row->checkpoint_seq, 'state' => json_decode($row->checkpoint, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function prune(string $threadId, int $keep): void
    {
        $this->conn()->transaction(function (ConnectionInterface $c) use ($threadId, $keep): void {
            $last = $this->lastSeq($threadId);
            $checkpoint = $this->checkpoint($threadId);
            $upTo = $last - $keep;
            if ($upTo <= $checkpoint['seq']) {
                return;
            }
            $state = $checkpoint['state'];
            foreach ($this->after($threadId, $checkpoint['seq'], $upTo - $checkpoint['seq']) as $event) {
                Projection::apply($state, $event);
            }
            $c->table('fancy_chat_threads')->where('thread_id', $threadId)->update([
                'checkpoint_seq' => $state['lastSeq'],
                'checkpoint' => Json::encode($state),
            ]);
            $c->table('fancy_chat_events')->where('thread_id', $threadId)->where('seq', '<=', $state['lastSeq'])->delete();
        }, 5);
    }

    public function index(string $hitlId, string $threadId, string $kind, string $state): void
    {
        $this->conn()->table('fancy_chat_hitl')->insertOrIgnore(['hitl_id' => $hitlId, 'thread_id' => $threadId, 'kind' => $kind, 'state' => $state]);
    }

    public function locate(string $hitlId): ?string
    {
        $thread = $this->conn()->table('fancy_chat_hitl')->where('hitl_id', $hitlId)->value('thread_id');

        return is_string($thread) ? $thread : null;
    }

    public function claim(string $hitlId, string $from, string $to): bool
    {
        return $this->conn()->table('fancy_chat_hitl')->where('hitl_id', $hitlId)->where('state', $from)->update(['state' => $to]) === 1;
    }

    public function setHitlState(string $hitlId, string $state): void
    {
        $this->conn()->table('fancy_chat_hitl')->where('hitl_id', $hitlId)->update(['state' => $state]);
    }

    public function staleThreads(int $beforeMs): array
    {
        return $this->conn()->table('fancy_chat_threads')->where('touched_at', '<=', $beforeMs)->orderBy('thread_id')->pluck('thread_id')->all();
    }

    public function openUndo(string $actionId, string $threadId, string $kind, int $deadlineMs): void
    {
        $this->conn()->table('fancy_chat_undo')->insert(['action_id' => $actionId, 'thread_id' => $threadId, 'kind' => $kind, 'deadline_ms' => $deadlineMs, 'state' => 'open']);
    }

    public function undoAction(string $actionId): ?array
    {
        $row = $this->conn()->table('fancy_chat_undo')->where('action_id', $actionId)->first();

        return $row === null ? null : self::undoRow($row);
    }

    public function claimUndo(string $actionId, string $from, string $to): bool
    {
        return $this->conn()->table('fancy_chat_undo')->where('action_id', $actionId)->where('state', $from)->update(['state' => $to]) === 1;
    }

    public function setUndoState(string $actionId, string $state): void
    {
        $this->conn()->table('fancy_chat_undo')->where('action_id', $actionId)->update(['state' => $state]);
    }

    public function expiredUndos(int $nowMs, int $limit): array
    {
        return $this->conn()->table('fancy_chat_undo')
            ->where('state', 'open')
            ->where('deadline_ms', '<=', $nowMs)
            ->orderBy('deadline_ms')
            ->orderBy('action_id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => self::undoRow($row))
            ->all();
    }

    /** @return array{actionId: string, threadId: string, kind: string, deadlineMs: int, state: string} */
    private static function undoRow(object $row): array
    {
        return ['actionId' => (string) $row->action_id, 'threadId' => (string) $row->thread_id, 'kind' => (string) $row->kind, 'deadlineMs' => (int) $row->deadline_ms, 'state' => (string) $row->state];
    }
}
