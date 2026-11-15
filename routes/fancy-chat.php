<?php

declare(strict_types=1);

use FancyChat\Http\ChatController;
use Illuminate\Support\Facades\Route;

Route::post('threads/{thread}/messages', [ChatController::class, 'send'])->name('fancy-chat.send');
Route::get('threads/{thread}/events', [ChatController::class, 'stream'])->name('fancy-chat.stream');
Route::get('threads/{thread}/events/poll', [ChatController::class, 'poll'])->name('fancy-chat.poll');
Route::get('threads/{thread}/snapshot', [ChatController::class, 'snapshot'])->name('fancy-chat.snapshot');
Route::post('threads/{thread}/cancel', [ChatController::class, 'cancel'])->name('fancy-chat.cancel');
Route::delete('threads/{thread}/queue/{message}', [ChatController::class, 'unsend'])->name('fancy-chat.unsend');
Route::post('approvals/{approval}/decision', [ChatController::class, 'decide'])->name('fancy-chat.decide');
Route::post('questions/{question}/answer', [ChatController::class, 'answer'])->name('fancy-chat.answer');
