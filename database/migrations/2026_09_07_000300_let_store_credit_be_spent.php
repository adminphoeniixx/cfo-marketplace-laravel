<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of an order the shopper paid with credit they already had.
 *
 * Kept on the order rather than inferred from the wallet ledger later. The
 * ledger says a debit happened; only this says how much of *this* order it
 * paid for, and that is the number a refund has to give back to the wallet
 * rather than to a card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('wallet_amount', 14, 2)->default(0)->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('wallet_amount');
        });
    }
};
