<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket now has two ends, not one.
 *
 * It was built for the only conversation that existed: a shopper writing to
 * the marketplace. But most of what a shopper needs is a *seller's* to answer
 * — where is my parcel, is this the right size — and a seller has nobody to
 * write to at all when the marketplace is the one holding something up.
 *
 * Three columns say who is talking to whom, and each means exactly one thing:
 *
 *   `audience`   who has to answer — the store, or the marketplace
 *   `vendor_id`  the store involved, either as the audience or as the author's
 *   `opened_by`  which side started it
 *
 * `customer_id` becomes nullable, because a seller writing to the marketplace
 * has no shopper in the conversation at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Existing rows are all shopper-to-marketplace, which is what the
            // default describes — no backfill needed for them.
            $table->string('audience', 20)->default('marketplace')->after('customer_id');
            $table->foreignId('vendor_id')->nullable()->after('audience')
                ->constrained()->nullOnDelete();
            $table->string('opened_by', 20)->default('customer')->after('vendor_id');
            // Who wrote it, where a seller did. Staff replies are already
            // recorded on the messages themselves.
            $table->foreignId('opened_by_user_id')->nullable()->after('opened_by')
                ->constrained('users')->nullOnDelete();

            $table->index(['audience', 'status']);
            $table->index(['vendor_id', 'status']);
        });

        // Nullable last, so the index above is built while the column is still
        // the shape SQLite is happy to rebuild around.
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('opened_by_user_id');
            $table->dropColumn(['audience', 'opened_by']);
        });

        DB::table('tickets')->whereNull('customer_id')->delete();

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
