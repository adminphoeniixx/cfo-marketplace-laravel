<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellations', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope')->default('partial');
            $table->string('reason');
            $table->text('note')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->boolean('restock')->default(true);
            $table->boolean('refund_requested')->default(false);
            $table->string('requested_by')->default('customer');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('cancellation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cancellation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_items');
        Schema::dropIfExists('cancellations');
    }
};
