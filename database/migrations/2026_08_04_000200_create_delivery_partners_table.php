<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Couriers every store starts with. The names match what existing orders
     * already carry, so historical fulfilments line up with the managed list.
     * Tracking URLs are a starting point — each one is editable.
     *
     * @var list<array{string, string, string}>
     */
    private array $defaults = [
        ['Delhivery', 'delhivery', 'https://www.delhivery.com/track/package/{tracking}'],
        ['Blue Dart', 'blue-dart', 'https://www.bluedart.com/tracking?trackFor=0&trackNo={tracking}'],
        ['Ekart', 'ekart', 'https://ekartlogistics.com/shipmenttrack/{tracking}'],
        ['DTDC', 'dtdc', 'https://www.dtdc.in/tracking.asp?strCnno={tracking}'],
        ['Shadowfax', 'shadowfax', 'https://shadowfax.in/track/{tracking}'],
    ];

    public function up(): void
    {
        Schema::create('delivery_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            // {tracking} is swapped for the order's tracking number.
            $table->string('tracking_url')->nullable();
            $table->string('support_phone')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        $now = now();

        DB::table('delivery_partners')->insert(
            collect($this->defaults)
                ->map(fn (array $row, int $index) => [
                    'name' => $row[0],
                    'code' => $row[1],
                    'tracking_url' => $row[2],
                    'support_phone' => null,
                    'notes' => null,
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
        Schema::dropIfExists('delivery_partners');
    }
};
