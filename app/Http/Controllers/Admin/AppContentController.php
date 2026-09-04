<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Faq;
use App\Models\LegalPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The words and pictures the shopper app draws.
 *
 * One screen for three small things — the home carousel, the help answers and
 * the legal pages — because each is a handful of rows nobody edits daily, and
 * three sidebar entries for that would be three places to forget.
 *
 * Every one of these used to live in the app's own source, so a sale, a new
 * answer or a corrected returns policy meant a release and two app stores.
 */
class AppContentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/content/Index', [
            'banners' => Banner::orderBy('position')->orderBy('id')->get()
                ->map(fn (Banner $banner) => [
                    ...$banner->only([
                        'id', 'title', 'subtitle', 'image_path', 'deeplink_route',
                        'position', 'is_active',
                    ]),
                    'deeplink_params' => (array) ($banner->deeplink_params ?? []),
                    'starts_at' => $banner->starts_at?->toDateString(),
                    'ends_at' => $banner->ends_at?->toDateString(),
                    // Whether the app is actually showing it right now, which
                    // is not the same as the switch being on.
                    'is_live' => Banner::live()->whereKey($banner->id)->exists(),
                ]),
            'faqs' => Faq::orderBy('position')->orderBy('id')->get()
                ->map(fn (Faq $faq) => $faq->only([
                    'id', 'question', 'answer', 'topic', 'position', 'is_active',
                ])),
            'pages' => LegalPage::orderBy('title')->get()
                ->map(fn (LegalPage $page) => [
                    ...$page->only(['id', 'slug', 'title', 'body', 'is_published']),
                    'updated_at' => $page->updated_at?->toIso8601String(),
                    // A published page with nothing on it is still invisible
                    // to the app, and the screen should say so.
                    'is_readable' => $page->is_published && filled($page->body),
                ]),
        ]);
    }

    public function storeBanner(Request $request): RedirectResponse
    {
        Banner::create($this->bannerRules($request));

        return back()->with('success', 'Banner added.');
    }

    public function updateBanner(Request $request, Banner $banner): RedirectResponse
    {
        $banner->update($this->bannerRules($request));

        return back()->with('success', 'Banner updated.');
    }

    public function toggleBanner(Banner $banner): RedirectResponse
    {
        $banner->update(['is_active' => ! $banner->is_active]);

        return back()->with('success', $banner->is_active ? 'Banner is showing.' : 'Banner hidden.');
    }

    public function destroyBanner(Banner $banner): RedirectResponse
    {
        $banner->delete();

        return back()->with('success', 'Banner deleted.');
    }

    public function storeFaq(Request $request): RedirectResponse
    {
        Faq::create($this->faqRules($request));

        return back()->with('success', 'Question added.');
    }

    public function updateFaq(Request $request, Faq $faq): RedirectResponse
    {
        $faq->update($this->faqRules($request));

        return back()->with('success', 'Question updated.');
    }

    public function destroyFaq(Faq $faq): RedirectResponse
    {
        $faq->delete();

        return back()->with('success', 'Question deleted.');
    }

    /**
     * The five pages are fixed — the app links to them by slug — so they are
     * edited, never created or deleted.
     */
    public function updatePage(Request $request, LegalPage $page): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:200000'],
            'is_published' => ['boolean'],
        ]);

        $page->update($data);

        return back()->with('success', "\"{$page->title}\" saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function bannerRules(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:180'],
            // A path in the storage zone, not a URL: the app is handed a
            // signed one at read time.
            'image_path' => ['nullable', 'string', 'max:500'],
            // The app's own route name. The marketplace carries it without
            // pretending to know the app's navigation.
            'deeplink_route' => ['nullable', 'string', 'max:120'],
            'deeplink_params' => ['nullable', 'array'],
            'position' => ['integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function faqRules(Request $request): array
    {
        return $request->validate([
            'question' => ['required', 'string', 'max:200'],
            'answer' => ['required', 'string', 'max:5000'],
            'topic' => ['required', Rule::in(['general', 'orders', 'returns', 'payments', 'account'])],
            'position' => ['integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ]);
    }
}
