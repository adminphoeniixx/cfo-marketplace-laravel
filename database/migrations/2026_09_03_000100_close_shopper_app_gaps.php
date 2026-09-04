<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the shopper app still had to invent for itself.
 *
 * Every change here closes a place where the app was hardcoding something the
 * server knew: the glyph beside a way to pay and beside a category, the
 * address the basket is heading to, when the parcel is due, and — the big one
 * — a record of money actually moving, so a non-COD order stops being
 * "assumed paid".
 */
return new class extends Migration
{
    /**
     * Codes the marketplace ships with, and the glyph each one wears. Matched
     * on shape rather than exact spelling, because the admin panel owns this
     * list and "cod" is not the only way to write cash on delivery.
     *
     * @var array<string, string>
     */
    private array $icons = [
        'upi' => '⚡',
        'card' => '💳',
        'bank' => '🏦',
        'cash' => '💵',
        'wallet' => '👛',
    ];

    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Nullable: an admin who never picks one still gets a glyph, from
            // `PaymentMethod::defaultIconFor()`. The column is the override.
            $table->string('icon', 8)->nullable()->after('description');
        });

        foreach ($this->icons as $needle => $icon) {
            DB::table('payment_methods')
                ->where('code', 'like', "%{$needle}%")
                ->update(['icon' => $icon]);
        }

        // Both spellings of cash on delivery, whichever the panel used.
        DB::table('payment_methods')->where('code', 'like', '%cod%')->update(['icon' => '💵']);
        DB::table('payment_methods')->where('code', 'like', '%net-banking%')->update(['icon' => '🏦']);

        Schema::table('categories', function (Blueprint $table) {
            /*
            | The tile's glyph, until there is a photograph.
            |
            | The prototype drew every category as an emoji and the app copied
            | that table into its own source; one column and an
            | `Emoji::forCategory()` fallback means the marketplace owns it and
            | a category added tomorrow still draws something.
            */
            $table->string('icon', 8)->nullable()->after('image_path');
        });

        Schema::table('carts', function (Blueprint $table) {
            /*
            | Where this basket is heading.
            |
            | The cart screen draws an address strip and the checkout screen
            | draws a picker; without this the two disagreed, because the cart
            | had to guess "the default" while checkout remembered a choice
            | only for as long as the app kept the id in memory.
            */
            $table->foreignId('selected_address_id')->nullable()->after('customer_id')
                ->constrained('customer_addresses')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            /*
            | When the parcel is due, decided once at checkout from the
            | delivery option the shopper actually chose. Stored rather than
            | recomputed, so the date on the order page is the date they were
            | promised even if the seller's rates change next week.
            */
            $table->date('eta_min_at')->nullable()->after('delivered_at');
            $table->date('eta_max_at')->nullable()->after('eta_min_at');
        });

        /*
        | Money moving, as its own record.
        |
        | An order's `transaction_id` is the receipt the shopper is shown; this
        | is the trail behind it — the intent that was opened, the id the
        | gateway gave back, and whether a webhook or the app's own verify call
        | got there first. Both paths write here, and the unique gateway order
        | id is what makes a replayed webhook a no-op rather than a double
        | capture.
        */
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway')->default('razorpay');
            $table->string('gateway_order_id')->unique();
            $table->string('gateway_payment_id')->nullable();
            $table->string('status')->default('created');
            $table->string('method')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('gateway_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['eta_min_at', 'eta_max_at']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_address_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('icon');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
