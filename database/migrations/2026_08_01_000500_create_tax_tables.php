<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_class_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('country')->default('IN');
            $table->string('state')->nullable();
            $table->string('postcode')->nullable();
            $table->decimal('rate', 8, 3)->default(0);
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('is_compound')->default(false);
            $table->boolean('applies_to_shipping')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_classes');
    }
};
