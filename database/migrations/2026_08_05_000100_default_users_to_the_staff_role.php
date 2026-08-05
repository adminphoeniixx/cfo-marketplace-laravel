<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column shipped defaulting to `admin`, so any row inserted without an
 * explicit role — self-registration included — arrived with full access.
 * Staff is the safe floor; admins are handed out from the Team screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('staff')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('admin')->change();
        });
    }
};
