<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('vehicle_type');
            $table->string('vehicle_model');
            $table->string('vehicle_color');
            $table->string('plate_number');
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('semester_start_date')->nullable();
            $table->date('semester_end_date')->nullable();
            $table->timestamps();

            // Composite index for efficient lookups by student + status
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_requests');
    }
};
