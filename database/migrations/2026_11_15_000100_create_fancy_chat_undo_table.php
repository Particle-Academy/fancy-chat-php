<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Undo windows. `state` is the lock (open, reverting, committing, reverted,
 * committed): a revert and a commit each claim `open` atomically, so only one
 * of them ever runs. The projection holds the label and the deadline the
 * client shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('fancy-chat.connection'))->create('fancy_chat_undo', function (Blueprint $table): void {
            $table->string('action_id', 128)->primary();
            $table->string('thread_id', 128)->index();
            $table->string('kind', 128);
            $table->unsignedBigInteger('deadline_ms');
            $table->string('state', 16);
            $table->index(['state', 'deadline_ms']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('fancy-chat.connection'))->dropIfExists('fancy_chat_undo');
    }
};
