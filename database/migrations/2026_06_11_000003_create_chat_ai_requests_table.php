<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('chat_conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();

            $table->foreignId('user_message_id')
                ->constrained('chat_messages')
                ->cascadeOnDelete();

            $table->foreignId('assistant_message_id')
                ->constrained('chat_messages')
                ->cascadeOnDelete();

            $table->string('status')->default('queued'); // queued, processing, completed, failed
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['chat_conversation_id', 'status']);
            $table->index('assistant_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_requests');
    }
};
