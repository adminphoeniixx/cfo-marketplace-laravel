<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Methods every store starts with. These match the values already written
     * on existing orders, so historical data lines up with the managed list.
     *
     * @var list<array{string, string, string}>
     */
    private array $defaults = [
        ['UPI', 'upi', 'Google Pay, PhonePe, Paytm and other UPI apps.'],
        ['Credit Card', 'credit-card', ''],
        ['Debit Card', 'debit-card', ''],
        ['Net Banking', 'net-banking', ''],
        ['Cash on Delivery', 'cash-on-delivery', 'Collected by the courier on delivery.'],
        ['Wallet', 'wallet', ''],
    ];

    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        $now = now();

        DB::table('payment_methods')->insert(
            collect($this->defaults)
                ->map(fn (array $row, int $index) => [
                    'name' => $row[0],
                    'code' => $row[1],
                    'description' => $row[2] !== '' ? $row[2] : null,
                    'is_active' => true,
                    'position' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
