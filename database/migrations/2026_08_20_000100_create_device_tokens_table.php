<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // FCM registration tokens have no documented maximum and already
            // run past 160 characters, so the hash carries the uniqueness —
            // same arrangement as `push_subscriptions`.
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('platform', 20)->default('android');
            $table->string('device_name')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
