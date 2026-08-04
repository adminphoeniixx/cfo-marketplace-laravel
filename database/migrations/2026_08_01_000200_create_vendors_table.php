<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('store_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('commission_type')->default('percentage');
            $table->decimal('commission_rate', 8, 2)->default(10);
            $table->string('contact_name')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country')->default('IN');
            $table->string('gst_number')->nullable();
            $table->string('payout_method')->default('bank');
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
