<?php

declare(strict_types=1);

namespace FancyChat\Http;

use Closure;
use FancyChat\ChatException;
use FancyChat\Contract\Json;
use FancyChat\FancyChat;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin HTTP over FancyChat. Every JSON body goes through the wire encoder, so
 * an empty map is `{}` and never `[]`. Errors are `{error: code}`.
 */
final class ChatController
{
    public function __construct(private FancyChat $chat) {}

    private function json(int $status, mixed $payload): Response
    {
        return new Response(Json::encode($payload), $status, ['Content-Type' => 'application/json']);
    }

    private function guard(Closure $fn): Response
    {
        try {
            return $fn();
        } catch (ChatException $e) {
            return $this->json($e->status, ['error' => $e->error]);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cursor(mixed $value): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : 0;
    }

    public function send(Request $request, string $thread): Response
    {
        return $this->guard(fn (): Response => $this->json(201, $this->chat->send($request, $thread, $this->body($request))));
    }

    public function poll(Request $request, string $thread): Response
    {
        return $this->guard(function () use ($request, $thread): Response {
            $this->chat->resolveActor($request);
            $this->chat->authorize($request, $thread, 'read');
            $wait = $request->query('wait');

            return $this->json(200, $this->chat->poll(
                $thread,
                $this->cursor($request->query('after')),
                is_numeric($wait) ? (int) $wait : (int) config('fancy-chat.poll.wait_ms', 25000),
            ));
        });
    }

    public function stream(Request $request, string $thread): Response
    {
        return $this->guard(function () use ($request, $thread): Response {
            $this->chat->resolveActor($request);
            $this->chat->authorize($request, $thread, 'read');
            $after = $this->cursor($request->headers->get('Last-Event-ID') ?? $request->query('after'));

            return new StreamedResponse(fn () => $this->chat->stream($thread, $after), 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
            ]);
        });
    }

    public function snapshot(Request $request, string $thread): Response
    {
        return $this->guard(function () use ($request, $thread): Response {
            $this->chat->resolveActor($request);
            $this->chat->authorize($request, $thread, 'read');
            $at = $request->query('at');
            $state = $this->chat->snapshot($thread, is_numeric($at) ? (int) $at : null);

            return $this->json(200, ['snapshot' => $state, 'seq' => $state['lastSeq']]);
        });
    }

    public function cancel(Request $request, string $thread): Response
    {
        return $this->guard(fn (): Response => $this->json(200, ['outcome' => $this->chat->cancel($request, $thread, $this->body($request))]));
    }

    public function unsend(Request $request, string $thread, string $message): Response
    {
        return $this->guard(fn (): Response => $this->json(200, $this->chat->unsend($request, $thread, $message)));
    }

    public function decide(Request $request, string $approval): Response
    {
        return $this->guard(fn (): Response => $this->json(200, $this->chat->decide($request, $approval, $this->body($request))));
    }

    public function answer(Request $request, string $question): Response
    {
        return $this->guard(fn (): Response => $this->json(200, $this->chat->answer($request, $question, $this->body($request))));
    }

    public function revertUndo(Request $request, string $action): Response
    {
        return $this->guard(fn (): Response => $this->json(200, $this->chat->revertUndo($request, $action)));
    }
}
