<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every deal push the marketplace sent, and who sent it.
 *
 * The other notifications in this system are consequences — an order moved, a
 * refund was decided — and the order or the refund is already the record of
 * them. A broadcast is somebody choosing to write to thousands of people at
 * once, which is exactly the kind of thing that needs a name against it
 * afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('heading');
            $table->string('message', 500);
            // An in-app route, not a URL — the shopper app owns its navigation.
            $table->string('link')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // How many shoppers it was addressed to when it went. Counted at
            // send time, because the number who qualify changes by the hour.
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_broadcasts');
    }
};
