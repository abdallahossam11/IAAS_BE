<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_login_otps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('challenge_token_hash');
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->foreign('student_id')
                ->references('id')
                ->on('students')
                ->cascadeOnDelete();

            $table->index('challenge_token_hash');
            $table->index(['student_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_login_otps');
    }
};
