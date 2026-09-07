<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices, as documents rather than as a rendering of an order.
 *
 * A marketplace makes two different supplies and only one of them is its own.
 * The seller supplies goods to the shopper; the marketplace supplies a service
 * — the platform, priced as commission — to the seller. Each is a taxable
 * supply between a different pair of parties, so each needs its own invoice,
 * its own GSTIN pair and its own consecutive series.
 *
 * The parties are snapshotted rather than joined. A vendor who moves premises
 * next March must not silently rewrite the address on an invoice issued today;
 * an issued document is a record of what was true when it was issued, and the
 * only safe way to keep that is to copy it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            // 'tax' — seller to shopper, for goods.
            // 'commission' — marketplace to seller, for the platform.
            $table->string('type');
            // The financial year the series belongs to. April to March, and
            // the reason a number is only unique alongside it.
            $table->string('financial_year', 7);
            $table->unsignedInteger('sequence');

            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_payout_id')->nullable()->constrained()->nullOnDelete();

            $table->string('currency', 3)->default('INR');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('shipping_total', 14, 2)->default(0);
            // Split three ways because the place of supply decides which two
            // of the three carry anything: CGST+SGST within a state, IGST
            // across one. Storing the split is the whole point of the record.
            $table->decimal('cgst_total', 14, 2)->default(0);
            $table->decimal('sgst_total', 14, 2)->default(0);
            $table->decimal('igst_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);

            // Supplier, recipient and lines exactly as they read when issued.
            $table->json('snapshot');

            $table->timestamp('issued_at');
            $table->timestamps();

            // One tax invoice per seller per order, and one commission invoice
            // per payout — enforced here rather than hoped for in a service.
            $table->unique(['order_id', 'vendor_id', 'type']);
            $table->unique(['vendor_payout_id', 'type']);
            $table->unique(['type', 'financial_year', 'vendor_id', 'sequence']);
            $table->index(['vendor_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
