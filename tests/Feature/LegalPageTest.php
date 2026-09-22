<?php

use App\Models\LegalPage;
use App\Models\Setting;
use Database\Seeders\LegalContentSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The legal pages on a URL.
 *
 * The app reads the same rows over the API; what is new here is that an app
 * store reviewer, or anyone else without the app installed, can read the
 * privacy policy in a browser. The rule the API set holds on this side too: a
 * page nobody has written does not exist.
 */
function writtenPage(string $slug = 'privacy', array $attributes = []): LegalPage
{
    $page = LegalPage::where('slug', $slug)->firstOrFail();

    $page->update([
        'body' => "We keep your address to deliver to it.\n\n## Grievance officer\n\nWrite to us.",
        'is_published' => true,
        ...$attributes,
    ]);

    return $page->refresh();
}

test('the privacy policy is readable without signing in', function () {
    writtenPage();

    $this->get('/legal/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('legal/Show')
            ->where('page.slug', 'privacy')
            ->where('page.title', 'Privacy policy')
            ->where('page.html', fn (string $html) => str_contains($html, '<h2 id="grievance-officer">Grievance officer</h2>'))
        );
});

test('a page nobody has written is a 404 rather than a blank policy', function () {
    // Published, but with no body — the state every slug starts in.
    LegalPage::where('slug', 'terms')->update(['is_published' => true, 'body' => null]);

    $this->get('/legal/terms')->assertNotFound();
});

test('an unpublished page stays private', function () {
    writtenPage('returns', ['is_published' => false]);

    $this->get('/legal/returns')->assertNotFound();
});

test('a slug nobody invented is a 404', function () {
    $this->get('/legal/cookies')->assertNotFound();
});

test('/privacy is the short address for the policy', function () {
    $this->get('/privacy')->assertRedirect('/legal/privacy');
});

test('a body written as html is served as html', function () {
    // How the returns policy and the licence list were actually written.
    writtenPage('licenses', [
        'body' => "<p>Built on other people's work.</p>\n<h2>Server</h2>\n<pre>brick/math — MIT</pre>",
    ]);

    $this->get('/legal/licenses')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.html', fn (string $html) => str_contains($html, '<pre>brick/math')
                && str_contains($html, "<p>Built on other people's work.</p>")
                // Escaping this was what put `<p>` on the live page.
                && ! str_contains($html, '&lt;p&gt;'))
        );
});

test('a script in a body never reaches the page as a script', function () {
    writtenPage('privacy', [
        'body' => 'Careful now <script>alert(1)</script> and <b>bold</b>.',
    ]);

    $this->get('/legal/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Shown as words — the renderer escapes this one before the
            // whitelist even sees it — and the markup around it is untouched.
            ->where('page.html', fn (string $html) => ! str_contains($html, '<script')
                && str_contains($html, '&lt;script&gt;')
                && str_contains($html, '<b>bold</b>'))
        );
});

test('anything that could ask a shopper for something is dropped whole', function () {
    writtenPage('privacy', [
        'body' => '<form action="//elsewhere"><input name="card"><p>Card number</p></form>'
            .'<p>Real words.</p><p>And <img src="x" onerror="steal()"> more.</p>',
    ]);

    $this->get('/legal/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.html', fn (string $html) => ! str_contains($html, '<form')
                // The form goes with its contents; a stray image just goes.
                && ! str_contains($html, 'Card number')
                && ! str_contains($html, '<img')
                && ! str_contains($html, 'onerror')
                && str_contains($html, '<p>Real words.</p>'))
        );
});

test('handlers and javascript links are stripped from a body', function () {
    writtenPage('privacy', [
        'body' => '<p onclick="steal()">Tap</p><a href="javascript:steal()">here</a>'
            .'<a href="https://example.test" target="_blank" class="x">there</a>',
    ]);

    $this->get('/legal/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.html', fn (string $html) => ! str_contains($html, 'onclick')
                && ! str_contains($html, 'javascript:')
                && ! str_contains($html, 'target=')
                && ! str_contains($html, 'class=')
                && str_contains($html, '<a href="https://example.test">there</a>'))
        );
});

test('the headings become a contents list', function () {
    writtenPage('privacy', [
        'body' => '<h2>What we collect</h2><p>Words.</p><h2>Your choices</h2><p>More.</p>',
    ]);

    $this->get('/legal/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.sections', 2)
            ->where('page.sections.0.id', 'what-we-collect')
            ->where('page.sections.0.title', 'What we collect')
            ->where('page.html', fn (string $html) => str_contains($html, 'id="what-we-collect"'))
        );
});

test('the footer only links to pages that are actually there', function () {
    writtenPage('privacy');
    writtenPage('returns');
    // Written but not published, so it must not be offered.
    writtenPage('terms', ['is_published' => false]);

    $this->get('/legal/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('others', 1)
            ->where('others.0.slug', 'returns')
        );
});

test('the seeder writes a policy and puts it on the page', function () {
    // Everything the text needs: four settings the panel already has, and the
    // two facts that may not be invented.
    Setting::put('store_name', 'Example Bazaar');
    Setting::put('store_email', 'support@example.test');
    Setting::put('store_phone', '+91 98100 00000');
    Setting::put('address', '12 Example Road, Noida 201301');
    putenv('LEGAL_ENTITY_NAME=Example Trading Private Limited');
    putenv('GRIEVANCE_OFFICER_NAME=A Named Person');

    $this->seed(LegalContentSeeder::class);

    $page = LegalPage::where('slug', 'privacy')->firstOrFail();

    expect($page->is_published)->toBeTrue()
        // Nothing left for somebody to discover on a public URL.
        ->and($page->body)->not->toMatch('/\[[^\]]+\]/')
        ->and($page->body)->toContain('Example Trading Private Limited');

    $this->get('/legal/privacy')->assertOk();

    putenv('LEGAL_ENTITY_NAME');
    putenv('GRIEVANCE_OFFICER_NAME');
});

test('the seeder never overwrites a policy somebody wrote', function () {
    writtenPage('privacy', ['body' => 'Our own words.']);

    $this->seed(LegalContentSeeder::class);

    expect(LegalPage::where('slug', 'privacy')->value('body'))->toBe('Our own words.');
});

test('the panel\'s own company fields write the policy', function () {
    // Nothing in the environment at all: a marketplace that has filled in
    // Settings → Store has already said who it is, and saying it twice is how
    // an invoice and a policy end up naming two different companies.
    Setting::put('legal_name', 'Example Trading Private Limited');
    Setting::put('address', '12 Example Road, Noida 201301');
    Setting::put('gst_number', '09AAOCC1589P1ZP');
    Setting::put('store_email', 'help@example.test');
    Setting::put('store_phone', '+91 98100 00000');
    putenv('GRIEVANCE_OFFICER_NAME=A Named Person');

    $this->seed(LegalContentSeeder::class);

    $body = (string) LegalPage::where('slug', 'privacy')->value('body');

    expect($body)->toContain('Example Trading Private Limited')
        ->and($body)->toContain('GSTIN 09AAOCC1589P1ZP')
        ->and($body)->toContain('help@example.test')
        ->and($body)->not->toMatch('/\[[^\]]+\]/');

    putenv('GRIEVANCE_OFFICER_NAME');
});

test('the address the box shipped with never reaches a published policy', function () {
    // `store_email` has a default, and a default is not an address anybody
    // answers. Better a visible placeholder than an invitation to write to
    // nobody.
    Setting::put('store_email', 'support@marketplace.test');

    $this->seed(LegalContentSeeder::class);

    expect((string) LegalPage::where('slug', 'privacy')->value('body'))
        ->toContain('[support email address]')
        ->and((string) LegalPage::where('slug', 'privacy')->value('body'))
        ->not->toContain('marketplace.test');
});

test('a policy with no gstin says nothing about one', function () {
    Setting::put('gst_number', '');

    $this->seed(LegalContentSeeder::class);

    expect((string) LegalPage::where('slug', 'privacy')->value('body'))
        ->not->toContain('GSTIN');
});
