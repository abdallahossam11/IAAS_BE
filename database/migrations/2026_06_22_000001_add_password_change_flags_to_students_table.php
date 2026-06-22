<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // True when an admin-created/temporary password is in place and the
            // student must set their own password before using normal features.
            $table->boolean('password_must_be_changed')->default(false)->after('password');
            // Timestamp of the last successful student-initiated password change.
            $table->timestamp('password_changed_at')->nullable()->after('password_must_be_changed');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['password_must_be_changed', 'password_changed_at']);
        });
    }
};
