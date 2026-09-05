<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for "I have a problem" to go.
 *
 * The help screen could show answers, a phone number and a chat link, and that
 * was the whole of support: anything not covered by an FAQ became a phone call
 * nobody wrote down, or an email to an address with no owner. A ticket is the
 * written record — of what was asked, who answered, and how long it took.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            // Quoted back to the shopper, and how they refer to it on the
            // phone. Sequential like an order number, for the same reason.
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            /*
            | The order this is about, where it is about one.
            |
            | Most support is about a specific parcel, and having the link
            | means staff open the ticket already knowing what was bought, from
            | whom, and where it got to — rather than asking the shopper to
            | repeat their own order number.
            */
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('category', 30)->default('other');
            $table->string('status', 20)->default('open');
            $table->string('priority', 20)->default('normal');
            // Staff, not the shopper. Null means nobody has picked it up.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            /*
            | Three timestamps that answer the three questions anybody asks of
            | a support desk: is anything waiting, how long did somebody wait
            | to be answered at all, and when was it done with.
            */
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_reply_at']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            /*
            | Exactly one of these is set, and which one is what "who said
            | this" means. A shopper's message has `customer_id`; a staff reply
            | has `user_id`.
            */
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            /*
            | A note between staff, never sent to the shopper.
            |
            | Support needs somewhere to write "spoke to the seller, they are
            | posting a replacement" without it reading as a promise to the
            | customer. The customer API filters these out; the panel shows
            | them tinted.
            */
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
