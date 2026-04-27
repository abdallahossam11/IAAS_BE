<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->unique();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();
            $table->decimal('gpa', 3, 2)->default(0);
            $table->integer('credits_completed')->default(0);
            $table->integer('credits_required')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
