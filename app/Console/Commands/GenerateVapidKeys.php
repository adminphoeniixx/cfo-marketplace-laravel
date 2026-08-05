<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Generate the VAPID key pair browser push notifications are signed with';

    public function handle(): int
    {
        if (config('webpush.public_key')) {
            $this->warn('A VAPID key pair is already configured.');
            $this->line('Replacing it un-subscribes every browser that has already opted in.');

            if (! $this->confirm('Generate a new pair anyway?', false)) {
                return self::SUCCESS;
            }
        }

        ['publicKey' => $public, 'privateKey' => $private] = VAPID::createVapidKeys();

        $this->newLine();
        $this->line('Add these to your environment (and to the Dokploy UI for production):');
        $this->newLine();
        $this->line("VAPID_PUBLIC_KEY={$public}");
        $this->line("VAPID_PRIVATE_KEY={$private}");
        $this->line('VAPID_SUBJECT=mailto:you@example.com');
        $this->newLine();
        $this->comment('The private key signs push requests — keep it out of version control.');

        return self::SUCCESS;
    }
}
