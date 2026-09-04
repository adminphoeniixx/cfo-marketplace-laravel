<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The words and pictures the app was carrying in its own source.
 *
 * Three tables, one reason: every one of these was hardcoded in the phone, so
 * changing a banner, answering a new question or correcting the returns policy
 * meant shipping a build and waiting on two app stores. The marketplace owns
 * them now, and the app draws whatever it is sent.
 */
return new class extends Migration
{
    /**
     * Kept here rather than read off the model, so this migration still says
     * what it did years after `LegalPage::PAGES` has moved on.
     *
     * @var array<string, string>
     */
    private const PAGES = [
        'terms' => 'Terms of use',
        'privacy' => 'Privacy policy',
        'returns' => 'Returns and refunds',
        'licenses' => 'Open-source licences',
        'grievance-officer' => 'Grievance officer',
    ];

    public function up(): void
    {
        /*
        | The carousel on the home screen.
        |
        | `deeplink_route` is the app's own route name and `deeplink_params`
        | whatever it needs to open it — the marketplace does not pretend to
        | know the app's navigation, it just carries the instruction.
        */
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('image_path')->nullable();
            $table->string('deeplink_route')->nullable();
            $table->json('deeplink_params')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            // A sale banner that takes itself down on Monday morning, rather
            // than one somebody has to remember to switch off.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            // Which help section it sits under; one list until there are
            // enough of them to need more.
            $table->string('topic')->default('general');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        /*
        | Terms, privacy, returns, licences, grievance officer.
        |
        | `updated_at` is the point of the table as much as the body is: an
        | Indian marketplace has to show when a policy last changed, and a
        | constant in the app cannot.
        */
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        /*
        | The five slugs exist from the start, unpublished and empty.
        |
        | The app links to them by name, so the rows have to be there for an
        | admin to write into — but an unwritten policy is not published, and
        | the API answers 404 rather than handing a shopper a blank page with
        | a legal-sounding title on it.
        */
        DB::table('legal_pages')->insert(array_map(fn (string $slug, string $title) => [
            'slug' => $slug,
            'title' => $title,
            'body' => null,
            'is_published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], array_keys(self::PAGES), array_values(self::PAGES)));
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('banners');
    }
};
