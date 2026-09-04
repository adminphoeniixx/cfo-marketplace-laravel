<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Text messages, which on this marketplace means one thing: the sign-in code.
 *
 * With no provider configured the code goes to the log and the API hands it
 * back as `debug_code` — which is how the demo and the test suite sign in
 * without an SMS bill. Production must configure one, because a login code
 * that only exists in a log file is a login nobody outside this server can
 * complete.
 *
 * Written against MSG91's **OTP** endpoint, which is what an OTP template on
 * that account actually accepts. Their flow endpoint takes the same key and the
 * same template id and answers `type: success` with a request id — and delivers
 * nothing, which is a day lost if you take the 200 at face value. The shape is
 * otherwise provider-agnostic: a URL, a key, a sender, a template, and the name
 * of the variable the code goes into.
 */
class Sms
{
    /**
     * Whether a real provider is wired up.
     */
    public static function enabled(): bool
    {
        return self::config('driver') === 'http'
            && self::config('key') !== null
            && self::config('url') !== null;
    }

    /**
     * Send one code, and say whether it left the building.
     *
     * Never throws: a provider having a bad morning must not turn a sign-in
     * screen into a 500. The caller is told false and can say "try again".
     */
    /**
     * @param  int  $ttl  How long the code stays valid, in seconds — passed on so
     *                    the provider's own copy expires when this one does.
     */
    public static function sendCode(string $phone, string $code, int $ttl = 600): bool
    {
        if (! self::enabled()) {
            /*
            | No provider: the code is logged so a developer can read it, and
            | only outside production. A plaintext login code in a production
            | log is a credential sitting in a file half the team can read —
            | and if this branch is being taken in production, the real bug is
            | that nobody configured a provider.
            */
            if (! app()->environment('production')) {
                Log::info('SMS not configured; login code issued', ['phone' => $phone, 'code' => $code]);
            } else {
                Log::warning('SMS provider is not configured — a shopper cannot sign in.', ['phone' => $phone]);
            }

            return false;
        }

        try {
            $response = Http::asJson()
                ->connectTimeout((int) self::config('connect_timeout', 5))
                ->timeout((int) self::config('timeout', 15))
                ->withHeaders(['authkey' => (string) self::config('key')])
                ->post((string) self::config('url'), array_filter([
                    'template_id' => self::config('template_id'),
                    'sender' => self::config('sender'),
                    'mobile' => self::msisdn($phone),
                    // The template's own variable name. MSG91's OTP templates
                    // almost always call it `otp`; a flow template might call
                    // it `var1`, and naming it wrong sends a message with a
                    // hole in it rather than an error.
                    (string) self::config('code_variable', 'otp') => $code,
                    // Minutes, and matched to this marketplace's own expiry so
                    // the two cannot disagree about when a code died.
                    'otp_expiry' => (int) ceil($ttl / 60),
                ], fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            Log::error('SMS provider unreachable.', ['phone' => $phone, 'error' => $e->getMessage()]);

            return false;
        }

        // MSG91 answers 200 with `type: error` on a bad template id, an
        // unapproved sender or an exhausted balance, so the status code alone
        // is not the test — the same trap as the courier API.
        $refused = $response->failed() || $response->json('type') === 'error';

        if ($refused) {
            Log::error('SMS provider refused the message.', [
                'phone' => $phone,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return false;
        }

        // The provider's own id for this message, and never the code itself.
        // It is the only handle anybody has when a shopper says the text never
        // arrived — without it a missing message cannot be chased at all.
        Log::info('Sign-in code handed to the SMS provider.', [
            'phone' => $phone,
            // MSG91 v5 puts it in `message`; other providers use `request_id`.
            'request_id' => $response->json('request_id') ?? $response->json('message'),
        ]);

        return true;
    }

    /**
     * A number in the shape a provider wants: digits, with a country code.
     *
     * Indian numbers are stored bare here, so a ten-digit one gets 91 in front
     * of it; anything already carrying a code is left alone.
     */
    protected static function msisdn(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return mb_strlen($digits) === 10
            ? (self::config('country_code', '91').$digits)
            : $digits;
    }

    protected static function config(string $key, mixed $default = null): mixed
    {
        $value = config("services.sms.{$key}", $default);

        return is_string($value) && trim($value) === '' ? $default : $value;
    }
}
