<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Firebase\FcmClient;
use App\Services\Firebase\ServiceAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks the Firebase setup end to end, because every part of it fails
 * quietly: bad credentials, the wrong project, an app that never registered.
 */
class FirebasePush extends Command
{
    protected $signature = 'firebase:check
                            {--user= : Send a test notification to this user id or email}
                            {--token= : Send a test notification to one raw device token}';

    protected $description = 'Verify the Firebase credentials, and optionally send a test push';

    public function handle(FcmClient $client): int
    {
        $account = ServiceAccount::resolve();

        if (! $account) {
            $this->error('No usable Firebase credentials.');
            $this->line('Set FIREBASE_CREDENTIALS to the service account JSON, the path to it, or a base64 copy.');
            $this->line('Current setting: '.($this->summarise((string) config('firebase.credentials'))));

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Project', $account->projectId());
        $this->components->twoColumnDetail('Service account', $account->clientEmail());
        $this->components->twoColumnDetail('Push enabled', $client->enabled() ? '<info>yes</info>' : '<comment>no (FIREBASE_PUSH_ENABLED)</comment>');
        // The check has to survive the migration not having run yet — that is
        // one of the things people run it to find out.
        $this->components->twoColumnDetail('Registered devices', Schema::hasTable('device_tokens')
            ? (string) DeviceToken::count()
            : '<comment>run php artisan migrate</comment>');

        // Minting a token is the only way to know the key actually signs.
        try {
            $client->forgetAccessToken();
            $client->accessToken();
            $this->components->twoColumnDetail('Google sign-in', '<info>accepted</info>');
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Google refused the service account: '.$e->getMessage());

            return self::FAILURE;
        }

        $tokens = $this->targets();

        if ($tokens === []) {
            $this->newLine();
            $this->info('Credentials are good. Pass --user or --token to send a test notification.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($tokens as $token) {
            $result = $client->send($token, [
                'notification' => [
                    'title' => config('app.name').' test',
                    'body' => 'If you can read this, push is working.',
                ],
                'data' => ['url' => '/admin', 'kind' => 'test'],
                'android' => [
                    'priority' => 'high',
                    'notification' => ['channel_id' => (string) config('firebase.android_channel')],
                ],
            ]);

            $this->components->twoColumnDetail(
                substr($token, 0, 24).'…',
                $result->success ? '<info>delivered</info>' : '<error>'.$result->describe().'</error>',
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function targets(): array
    {
        if ($token = $this->option('token')) {
            return [(string) $token];
        }

        if (! $needle = $this->option('user')) {
            return [];
        }

        $user = User::query()
            ->when(is_numeric($needle),
                fn ($query) => $query->whereKey((int) $needle),
                fn ($query) => $query->where('email', $needle))
            ->first();

        if (! $user) {
            $this->warn("No user matched [{$needle}].");

            return [];
        }

        if (! Schema::hasTable('device_tokens')) {
            return [];
        }

        $tokens = array_values(array_map(
            strval(...),
            DeviceToken::where('user_id', $user->id)->pluck('token')->all(),
        ));

        if ($tokens === []) {
            $this->warn("{$user->email} has not registered a device yet.");
        }

        return $tokens;
    }

    /**
     * Never print the credentials themselves back at the operator.
     */
    private function summarise(string $source): string
    {
        if ($source === '') {
            return '(empty)';
        }

        return str_starts_with(trim($source), '{')
            ? 'inline JSON ('.strlen($source).' bytes) — could not be parsed'
            : $source.(is_file($source) ? ' (unreadable)' : ' (no such file)');
    }
}
