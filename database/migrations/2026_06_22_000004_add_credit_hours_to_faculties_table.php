<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            // Each faculties row now represents a selectable academic program.
            // Nullable so existing production rows are not broken by the migration;
            // they can be backfilled by FacultyCreditHoursSeeder.
            $table->string('sector')->nullable()->after('name');
            $table->string('field')->nullable()->after('sector');
            $table->unsignedSmallInteger('credit_hours')->nullable()->after('field');
        });
    }

    public function down(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->dropColumn(['sector', 'field', 'credit_hours']);
        });
    }
};
