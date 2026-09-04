<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\LegalPage;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * The things the app was told to hardcode.
 *
 * A support number, an opening-hours line, five legal pages and the link that
 * sends a shopper off to open their own store: none of it is shopping, all of
 * it was in the phone's own source, and every correction meant a release.
 *
 * Everything here is open — a shopper who has been signed out still has to be
 * able to read the privacy policy and phone somebody.
 */
class ContentController extends Controller
{
    /**
     * Settings the app needs before it draws anything.
     */
    public function appConfig(): JsonResponse
    {
        return response()->json([
            'data' => [
                'store_name' => Setting::cached('store_name', config('app.name')),
                'currency' => Setting::cached('currency', 'INR'),
                // "Sell on <marketplace>" — the app opens this rather than
                // carrying a URL that changed the week after it shipped.
                'seller_onboarding_url' => Setting::cached('seller_onboarding_url') ?: null,
                'support_email' => Setting::cached('store_email') ?: null,
                'support_phone' => Setting::cached('store_phone') ?: null,
                'legal_pages' => LegalPage::readable()
                    ->orderBy('title')
                    ->get(['slug', 'title', 'updated_at'])
                    ->map(fn (LegalPage $page) => [
                        'slug' => $page->slug,
                        'title' => $page->title,
                        'updated_at' => $page->updated_at?->toIso8601String(),
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * How to reach a human, and the questions that save them a call.
     */
    public function supportConfig(): JsonResponse
    {
        $chatUrl = Setting::cached('support_chat_url') ?: null;

        return response()->json([
            'data' => [
                // Enabled means there is somewhere to send them: a switch that
                // is on with no URL behind it is a button that does nothing.
                'chat_enabled' => $chatUrl !== null,
                'chat_provider' => $chatUrl ? (Setting::cached('support_chat_provider') ?: 'web') : null,
                'chat_session_url' => $chatUrl,
                'phone' => Setting::cached('store_phone') ?: null,
                'email' => Setting::cached('store_email') ?: null,
                'hours' => Setting::cached('support_hours') ?: null,
                'faq' => Faq::live()
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (Faq $faq) => [
                        'id' => $faq->id,
                        'question' => $faq->question,
                        'answer' => $faq->answer,
                        'topic' => $faq->topic,
                        'sort_order' => (int) $faq->position,
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * The legal pages that have actually been written.
     */
    public function legalIndex(): JsonResponse
    {
        return response()->json([
            'data' => LegalPage::readable()
                ->orderBy('title')
                ->get()
                ->map(fn (LegalPage $page) => [
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'updated_at' => $page->updated_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * One page, in full. 404 while nobody has written it, so the app links to
     * a blank screen no more than it hardcodes the words.
     */
    public function legal(string $page): JsonResponse
    {
        $model = LegalPage::readable()->where('slug', $page)->firstOrFail();

        return response()->json([
            'data' => [
                'slug' => $model->slug,
                'title' => $model->title,
                'body' => $model->body,
                'updated_at' => $model->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
