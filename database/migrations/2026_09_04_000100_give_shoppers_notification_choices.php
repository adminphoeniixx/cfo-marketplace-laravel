<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a shopper wants to be told about, and how.
 *
 * The settings screen had one switch behind it — `accepts_marketing` — and
 * drew four. The rest were remembered in the phone, which meant a reinstall
 * turned them all back on and a second device never agreed with the first.
 * Email marketing keeps its old column, because it is the one the marketplace
 * already sends against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Default on: someone who has not chosen still expects to hear
            // that their parcel is out for delivery.
            $table->boolean('push_enabled')->default(true)->after('accepts_marketing');
            $table->boolean('notify_order_updates')->default(true)->after('push_enabled');
            $table->boolean('sms_order_updates')->default(true)->after('notify_order_updates');
            // Off by default: deals are marketing, and marketing is opt-in.
            $table->boolean('notify_deals')->default(false)->after('sms_order_updates');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'push_enabled',
                'notify_order_updates',
                'sms_order_updates',
                'notify_deals',
            ]);
        });
    }
};
