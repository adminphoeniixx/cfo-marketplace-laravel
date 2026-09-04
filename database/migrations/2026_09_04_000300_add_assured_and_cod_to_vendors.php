<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two filter chips the app was drawing with nothing behind them.
 *
 * "Assured" is the marketplace's own badge — a store it has checked and will
 * stand behind — so it belongs to the marketplace, not to the seller, and is
 * set in the admin panel rather than in the seller app. Cash on delivery is
 * per store as well: a seller who will not handle cash says so once, instead
 * of on every product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // Off until somebody vouches for the store: a badge that defaults
            // to on is not a badge.
            $table->boolean('is_assured')->default(false)->after('status');
            // On by default, because most stores here do take cash.
            $table->boolean('cod_available')->default(true)->after('is_assured');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['is_assured', 'cod_available']);
        });
    }
};
