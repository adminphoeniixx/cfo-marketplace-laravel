<?php

namespace App\Http\Controllers;

use App\Models\LegalPage;
use App\Models\Setting;
use App\Support\LegalHtml;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The legal pages, in a browser.
 *
 * The shopper app reads these over `GET /api/legal/{slug}`, which is all a
 * phone needs and no use at all to an app store: both stores ask for a URL
 * anybody can open — signed out, on a desktop, before the app is installed.
 *
 * Same rows, same rule as the API — a page nobody has written is a 404 rather
 * than a blank screen under a legal title. Only the surface is new.
 */
class LegalPageController extends Controller
{
    public function show(string $page): Response
    {
        $model = LegalPage::readable()->where('slug', $page)->firstOrFail();

        // Some bodies were written as HTML and some as Markdown. Both are
        // rendered and then put through a whitelist — see `LegalHtml`.
        $rendered = LegalHtml::render($model->body);

        return Inertia::render('legal/Show', [
            'page' => [
                'slug' => $model->slug,
                'title' => $model->title,
                'html' => $rendered['html'],
                // The page's own h2s, for the contents beside a long policy.
                'sections' => $rendered['sections'],
                'updated_at' => $model->updated_at?->toDateString(),
            ],
            // Only the ones that are actually readable: the footer of a policy
            // is no place to link to a 404.
            'others' => LegalPage::readable()
                ->whereKeyNot($model->getKey())
                ->orderBy('title')
                ->get(['slug', 'title'])
                ->map(fn (LegalPage $other) => $other->only(['slug', 'title']))
                ->all(),
            'store' => [
                'name' => Setting::cached('store_name', config('app.name')),
                'email' => Setting::cached('store_email') ?: null,
            ],
        ]);
    }
}
