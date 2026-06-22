<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_login_otps', function (Blueprint $table) {
            // Separates one-time codes by intent so a login OTP can never be used
            // for a password reset, and a password-reset OTP can never log in.
            // Defaults to 'login' so existing rows keep their original meaning.
            $table->string('purpose', 32)->default('login')->after('student_id');

            $table->index(['student_id', 'purpose', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::table('student_login_otps', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'purpose', 'used_at']);
            $table->dropColumn('purpose');
        });
    }
};
