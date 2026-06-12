<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('chat_conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();

            $table->string('role');                         // user, assistant
            $table->longText('content')->nullable();        // nullable for pending assistant placeholders
            $table->string('status')->default('completed'); // completed, pending, failed
            $table->unsignedInteger('sequence_number');
            $table->uuid('client_message_id')->nullable();  // nullable in DB; assistant placeholders have none
            $table->timestamps();

            $table->unique('client_message_id');                              // global uniqueness
            $table->unique(['chat_conversation_id', 'sequence_number']);      // per-conversation ordering
            $table->index(['chat_conversation_id', 'role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
