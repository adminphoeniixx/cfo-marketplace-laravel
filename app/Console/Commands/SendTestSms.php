<?php

namespace App\Console\Commands;

use App\Services\Sms;
use Illuminate\Console\Command;

/**
 * Send one real text, on purpose.
 *
 * Wiring an SMS provider up has three ways to look configured and not be: a
 * template id that does not exist, a sender header nobody approved, and an
 * account with no balance. All three answer 200. This is how you find out
 * before a shopper does, and it sends to a number you name rather than to
 * anybody in the database.
 */
class SendTestSms extends Command
{
    protected $signature = 'sms:test {phone : The number to text, with or without a country code}';

    protected $description = 'Send one test sign-in code through the configured SMS provider';

    public function handle(): int
    {
        if (! Sms::enabled()) {
            $this->components->error('No SMS provider is configured — set SMS_DRIVER=http and a key.');

            return self::FAILURE;
        }

        $phone = (string) $this->argument('phone');
        $code = (string) random_int(100000, 999999);

        $this->components->info("Sending {$code} to {$phone}…");

        if (! Sms::sendCode($phone, $code)) {
            $this->components->error('The provider refused it. The reason is in the log.');

            return self::FAILURE;
        }

        $this->components->info('Accepted by the provider. If nothing arrives, the template or the sender is wrong.');

        return self::SUCCESS;
    }
}
