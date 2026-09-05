<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials on the courier row, so a second one does not need a deploy.
 *
 * Delhivery's token lives in the environment, which is the right place for a
 * secret and the wrong place for a list somebody adds to: connecting Shiprocket
 * meant an env change, a redeploy, and a marketplace whose couriers only its
 * developer could add.
 *
 * `driver` is what makes a row more than a name and a tracking link — it names
 * the client that knows how to talk to that courier. A partner with no driver
 * stays exactly what every partner is today: a label, a link, and a phone
 * number.
 *
 * `credentials` is cast `encrypted:array`, so what sits in Postgres is
 * ciphertext. Anyone reading a database dump gets nothing, and rotating a key
 * is a form field rather than a release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_partners', function (Blueprint $table) {
            $table->string('driver', 30)->nullable()->after('code');
            $table->text('credentials')->nullable()->after('driver');
            /*
            | The last time somebody proved these credentials work, and what
            | went wrong if they did not.
            |
            | A courier that accepts a booking and delivers nothing is the
            | failure this marketplace has already met once, so "we asked, and
            | it answered" is worth writing down rather than assuming.
            */
            $table->timestamp('connected_at')->nullable()->after('credentials');
            $table->string('connection_error')->nullable()->after('connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_partners', function (Blueprint $table) {
            $table->dropColumn(['driver', 'credentials', 'connected_at', 'connection_error']);
        });
    }
};
