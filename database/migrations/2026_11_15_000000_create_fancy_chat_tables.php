<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('fancy-chat.connection');

        Schema::connection($connection)->create('fancy_chat_threads', function (Blueprint $table): void {
            $table->string('thread_id', 128)->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->unsignedBigInteger('checkpoint_seq')->default(0);
            $table->longText('checkpoint')->nullable();
            $table->unsignedBigInteger('touched_at')->default(0)->index();
        });

        Schema::connection($connection)->create('fancy_chat_events', function (Blueprint $table): void {
            $table->id();
            $table->string('thread_id', 128);
            $table->unsignedBigInteger('seq');
            $table->string('type', 128);
            $table->longText('payload');
            $table->unique(['thread_id', 'seq']);
        });

        Schema::connection($connection)->create('fancy_chat_hitl', function (Blueprint $table): void {
            $table->string('hitl_id', 128)->primary();
            $table->string('thread_id', 128)->index();
            $table->string('kind', 16);
            $table->string('state', 32);
        });
    }

    public function down(): void
    {
        $connection = config('fancy-chat.connection');
        Schema::connection($connection)->dropIfExists('fancy_chat_hitl');
        Schema::connection($connection)->dropIfExists('fancy_chat_events');
        Schema::connection($connection)->dropIfExists('fancy_chat_threads');
    }
};
