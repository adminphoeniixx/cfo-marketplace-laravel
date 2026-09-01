<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shopper app needs that the marketplace never had.
 *
 * Until now a customer was a record staff typed into the panel; nothing let
 * one sign in, keep a basket between devices, or say what they thought of what
 * they bought. Five tables and one column close that gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Nullable on purpose: customers created by staff, or ones who only
            // ever sign in with an OTP, never set one.
            $table->string('password')->nullable()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified');
        });

        /*
        | One open basket per shopper, and one per signed-out device.
        |
        | The guest basket is keyed by `token` so the app can fill a cart before
        | anyone signs in; signing in merges that basket into the customer's own
        | rather than throwing it away.
        */
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->nullable()->unique();
            $table->string('coupon_code', 60)->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            // "Save for later" keeps the line on the basket instead of moving it
            // to the wishlist, so the chosen variant survives.
            $table->boolean('saved_for_later')->default(false);
            $table->timestamps();

            $table->unique(['cart_id', 'product_id', 'product_variant_id'], 'cart_items_line_unique');
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
        });

        /*
        | A rating is tied to the order it came from: that is what makes it a
        | verified purchase, and what stops the same person rating a product
        | twice for the same order.
        */
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('status')->default('published');
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'customer_id']);
            $table->index(['product_id', 'status']);
        });

        /*
        | Push tokens for shopper installs.
        |
        | A table of its own rather than a polymorphic column on
        | `device_tokens`: that table is the seller app's, keyed to `users`,
        | and widening it would put two audiences in one place for no gain.
        */
        Schema::create('customer_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('platform', 20)->default('android');
            $table->string('device_name')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });

        /*
        | Coupons the shopper can apply themselves. Orders already record the
        | code they were placed with; this is the list that decides whether a
        | code means anything.
        */
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('description')->nullable();
            $table->string('type')->default('flat');     // flat | percent | free_shipping
            $table->decimal('value', 12, 2)->default(0);
            $table->decimal('min_spend', 12, 2)->default(0);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('customer_device_tokens');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['password', 'phone_verified_at']);
        });
    }
};
