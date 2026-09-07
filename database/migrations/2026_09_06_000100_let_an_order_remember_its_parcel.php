<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the courier knows about a parcel, written where the marketplace can read it.
 *
 * Until now an order carried a waybill and two timestamps, and everything else
 * the courier said was inferred from them. That works while a parcel goes out
 * and arrives, and breaks the moment it does anything else: a delivery that was
 * attempted and failed, a parcel coming back, one that is simply lost. All
 * three read as "shipped, not delivered yet" — indefinitely.
 *
 * `shipment_status` is the courier's own account of the parcel, normalised;
 * `status` stays the marketplace's account of the order, which is a different
 * question and moves at a different pace. A parcel can be returning while the
 * order is still very much a sale nobody has refunded.
 *
 * `delivery_attempts` is what makes a failed attempt visible rather than
 * silent — three of them is a conversation with the shopper, not a wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipment_status', 30)->nullable()->after('carrier');
            // The label, as the courier's own URL. Stored rather than fetched
            // per view: both providers charge a call for it, and the seller
            // printing one twice should not cost two.
            $table->string('shipment_label_url', 500)->nullable()->after('shipment_status');
            $table->timestamp('pickup_scheduled_at')->nullable()->after('shipment_label_url');
            $table->unsignedTinyInteger('delivery_attempts')->default(0)->after('pickup_scheduled_at');
            $table->timestamp('returned_at')->nullable()->after('delivered_at');

            // The sync command and the webhooks both ask for "parcels still
            // moving", which is this.
            $table->index(['shipment_status', 'delivered_at'], 'orders_shipment_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_shipment_state_index');
            $table->dropColumn([
                'shipment_status',
                'shipment_label_url',
                'pickup_scheduled_at',
                'delivery_attempts',
                'returned_at',
            ]);
        });
    }
};
