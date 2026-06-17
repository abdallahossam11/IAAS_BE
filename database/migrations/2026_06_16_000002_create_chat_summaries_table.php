<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_summaries', function (Blueprint $table) {
            $table->id();

            // session_id + user_id mirror the AI memory keys. No FK to students:
            // the AI service upserts this table directly, and the pair is the
            // contract's idempotency key.
            $table->string('session_id');
            $table->unsignedBigInteger('user_id');
            $table->longText('summary_text');
            $table->timestamps();

            $table->unique(['user_id', 'session_id']);
            $table->index('session_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_summaries');
    }
};
