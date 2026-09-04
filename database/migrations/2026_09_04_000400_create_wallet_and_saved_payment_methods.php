<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the payments screen could not draw.
 *
 * A shopper's saved ways to pay, and whatever credit the marketplace owes
 * them. Both were static in the app: a hardcoded card row that belonged to
 * nobody, and a balance of zero that was never anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | A saved way to pay — and deliberately not enough of one to pay with.
        |
        | Card numbers never touch this marketplace: the app tokenises with the
        | gateway and sends back what is safe to show plus the gateway's own
        | token. `masked_value` is for the eye ("•••• 4242", "priya@okhdfc"),
        | `gateway_token` is what a future charge would be made against, and
        | neither is a card number. There is no column here that could hold
        | one.
        */
        Schema::create('customer_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label')->nullable();
            $table->string('masked_value', 60)->nullable();
            $table->string('provider', 60)->nullable();
            $table->string('gateway', 40)->default('razorpay');
            $table->string('gateway_token')->nullable();
            // Cards only, and month precision — a card expires at the end of
            // its month, which is the only thing the screen has to say.
            $table->date('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            // True only where the gateway said so: a row the shopper typed in
            // is a note to themselves until something has been charged to it.
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
        });

        /*
        | Store credit, as a ledger rather than a number.
        |
        | A balance column would be a running total nobody can audit; a row per
        | movement can be shown back to the shopper as "where did this come
        | from", which is exactly what the wallet screen lists. Positive
        | amounts are credit in, negative is credit spent.
        */
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('kind', 30)->default('credit');
            $table->string('description')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            // Credit that lapses. Null is credit that does not.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('customer_payment_methods');
    }
};
