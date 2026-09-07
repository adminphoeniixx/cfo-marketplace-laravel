<?php

use App\Jobs\SendFcmMessage;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\OrderPlaced;
use App\Notifications\TeamMemberChanged;
use App\Services\Firebase\FcmClient;
use App\Services\Firebase\ServiceAccount;
use App\Services\Notifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A throwaway service account, signed with a real key pair so the assertion
 * the client builds is genuinely signable.
 */
function fakeFirebaseCredentials(): string
{
    static $json = null;

    if ($json === null) {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $private);

        $json = json_encode([
            'type' => 'service_account',
            'project_id' => 'cfo-test',
            'private_key' => $private,
            'client_email' => 'tester@cfo-test.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    return $json;
}

function withFirebase(): void
{
    config()->set('firebase.credentials', fakeFirebaseCredentials());
    config()->set('firebase.enabled', true);
}

/** Google's token endpoint plus a successful send. */
function fakeFirebaseHttp(array $sendResponse = ['name' => 'projects/cfo-test/messages/1'], int $sendStatus = 200): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response($sendResponse, $sendStatus),
    ]);
}

/*
|--------------------------------------------------------------------------
| Credentials
|--------------------------------------------------------------------------
*/

test('push stays off until credentials are configured', function () {
    config()->set('firebase.credentials', '');

    expect(ServiceAccount::resolve())->toBeNull()
        ->and(app(FcmClient::class)->enabled())->toBeFalse();
});

test('credentials are read as raw json, as base64, or from a file', function () {
    $json = fakeFirebaseCredentials();

    config()->set('firebase.credentials', $json);
    expect(ServiceAccount::resolve()?->projectId())->toBe('cfo-test');

    config()->set('firebase.credentials', base64_encode($json));
    expect(ServiceAccount::resolve()?->projectId())->toBe('cfo-test');

    $path = tempnam(sys_get_temp_dir(), 'fb').'.json';
    file_put_contents($path, $json);
    config()->set('firebase.credentials', $path);
    expect(ServiceAccount::resolve()?->clientEmail())->toBe('tester@cfo-test.iam.gserviceaccount.com');

    unlink($path);
});

test('a half-filled service account counts as no credentials at all', function () {
    config()->set('firebase.credentials', json_encode(['type' => 'service_account', 'project_id' => 'cfo-test']));

    expect(ServiceAccount::resolve())->toBeNull();
});

test('the access token is minted once and then cached', function () {
    withFirebase();
    fakeFirebaseHttp();

    $client = app(FcmClient::class);

    expect($client->accessToken())->toBe('ya29.fake')
        ->and($client->accessToken())->toBe('ya29.fake');

    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Sending
|--------------------------------------------------------------------------
*/

test('a message goes to the project endpoint with the device token', function () {
    withFirebase();
    fakeFirebaseHttp();

    $result = app(FcmClient::class)->send('device-abc', [
        'notification' => ['title' => 'Hi', 'body' => 'There'],
    ]);

    expect($result->success)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/cfo-test/messages:send'
        && $request['message']['token'] === 'device-abc'
        && $request['message']['notification']['title'] === 'Hi'
        && $request->hasHeader('Authorization', 'Bearer ya29.fake'));
});

test('an unregistered token is reported as gone rather than retried', function () {
    withFirebase();
    fakeFirebaseHttp([
        'error' => [
            'status' => 'NOT_FOUND',
            'message' => 'Requested entity was not found.',
            'details' => [['errorCode' => 'UNREGISTERED']],
        ],
    ], 404);

    $result = app(FcmClient::class)->send('stale-token', []);

    expect($result->success)->toBeFalse()
        ->and($result->gone())->toBeTrue()
        ->and($result->retryable())->toBeFalse();
});

test('firebase being briefly down is retryable', function () {
    withFirebase();
    fakeFirebaseHttp(['error' => ['status' => 'UNAVAILABLE']], 503);

    $result = app(FcmClient::class)->send('device-abc', []);

    expect($result->gone())->toBeFalse()
        ->and($result->retryable())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The channel
|--------------------------------------------------------------------------
*/

test('every registered device gets its own queued job', function () {
    withFirebase();
    Queue::fake();

    [$me, $store] = actingAsSeller();
    DeviceToken::remember($me, 'phone-token');
    DeviceToken::remember($me, 'tablet-token', 'ios');

    // Someone else's phone must not be in the queue.
    $other = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id]);
    DeviceToken::remember($other, 'their-phone');

    $me->notify(new TeamMemberChanged('Priya', 'added', 'vendor', 'Meera'));

    Queue::assertPushed(SendFcmMessage::class, 2);
});

test('nothing is queued while firebase is unconfigured', function () {
    config()->set('firebase.credentials', '');
    Queue::fake();

    [$me] = actingAsSeller();
    DeviceToken::remember($me, 'phone-token');

    $me->notify(new TeamMemberChanged('Priya', 'added', 'vendor', 'Meera'));

    Queue::assertNothingPushed();
});

test('a delivered message stamps the device, a dead one deletes it', function () {
    withFirebase();
    [$me] = actingAsSeller();

    $device = DeviceToken::remember($me, 'phone-token');

    // One delivery, then the same token coming back as uninstalled.
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::sequence()
            ->push(['name' => 'projects/cfo-test/messages/1'], 200)
            ->push(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);

    // The job is told which table the id belongs to: a seller and a shopper
    // can both hold device 7.
    $job = fn () => (new SendFcmMessage(DeviceToken::class, $device->id, ['notification' => ['title' => 'Hi']]))
        ->handle(app(FcmClient::class));

    $job();
    expect($device->fresh()->last_sent_at)->not->toBeNull();

    $job();
    expect(DeviceToken::find($device->id))->toBeNull();
});

test('the message carries the notification title, body and link', function () {
    withFirebase();
    config()->set('app.url', 'https://cfo.test');

    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $message = (new OrderPlaced($order->load('items', 'customer')))->toFcm($me);

    expect($message['notification']['title'])->toBe("New order {$order->number}")
        ->and($message['data']['url'])->toBe("/admin/orders/{$order->id}")
        ->and($message['data']['kind'])->toBe('order-placed')
        ->and($message['webpush']['fcm_options']['link'])->toBe("https://cfo.test/admin/orders/{$order->id}")
        ->and($message['android']['notification']['channel_id'])->toBe(config('firebase.android_channel'));
});

test('a plain http deployment sends without a web link, since fcm rejects those', function () {
    config()->set('app.url', 'http://localhost:8000');
    [$me] = actingAsSeller();

    expect((new TeamMemberChanged('Priya', 'added', 'vendor', 'Meera'))->toFcm($me))
        ->not->toHaveKey('webpush');
});

/*
|--------------------------------------------------------------------------
| The API
|--------------------------------------------------------------------------
*/

test('an app registers its device token and forgets it again', function () {
    withFirebase();
    [$me] = actingAsSeller();

    $this->postJson(route('api.seller.push.device.register'), [
        'token' => 'phone-token',
        'platform' => 'android',
        'device_name' => 'Meera\'s phone',
    ])->assertCreated();

    // A refreshed registration of the same token updates rather than piles up.
    $this->postJson(route('api.seller.push.device.register'), [
        'token' => 'phone-token',
        'platform' => 'android',
    ])->assertCreated();

    expect(DeviceToken::where('user_id', $me->id)->count())->toBe(1);

    $this->getJson(route('api.seller.push.settings'))
        ->assertOk()
        ->assertJsonPath('firebase.enabled', true)
        ->assertJsonPath('firebase.project_id', 'cfo-test')
        ->assertJsonPath('firebase.devices', 1);

    $this->deleteJson(route('api.seller.push.device.forget'), ['token' => 'phone-token'])
        ->assertOk();

    expect(DeviceToken::where('user_id', $me->id)->count())->toBe(0);
});

test('an unknown platform is refused', function () {
    [$me] = actingAsSeller();

    $this->postJson(route('api.seller.push.device.register'), [
        'token' => 'phone-token',
        'platform' => 'blackberry',
    ])->assertJsonValidationErrorFor('platform');
});

test('a shared phone moves to whoever signed in last', function () {
    [$first, $store] = actingAsSeller();
    $second = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id]);

    DeviceToken::remember($first, 'shared-phone');
    DeviceToken::remember($second, 'shared-phone');

    expect(DeviceToken::where('token_hash', DeviceToken::hashFor('shared-phone'))->count())->toBe(1)
        ->and(DeviceToken::where('user_id', $first->id)->count())->toBe(0)
        ->and(DeviceToken::where('user_id', $second->id)->count())->toBe(1);
});

test('signing out drops that phone, signing out everywhere drops them all', function () {
    [$me] = actingAsSeller();
    DeviceToken::remember($me, 'phone-token');
    DeviceToken::remember($me, 'tablet-token', 'ios');

    $this->postJson(route('api.seller.logout'), ['device_token' => 'phone-token'])->assertOk();

    expect(DeviceToken::where('user_id', $me->id)->pluck('token')->all())->toBe(['tablet-token']);

    // The bearer token is gone with the sign-out, so act as the seller again.
    $this->withHeader('Authorization', 'Bearer '.$me->createToken('another device')->plainTextToken);

    $this->postJson(route('api.seller.logout-all'))->assertOk();

    expect(DeviceToken::where('user_id', $me->id)->count())->toBe(0);
});

test('a real notification reaches a seller phone end to end', function () {
    withFirebase();
    fakeFirebaseHttp();

    [$me, $store] = actingAsSeller();
    DeviceToken::remember($me, 'phone-token');

    Notifier::send(new OrderPlaced(orderForStore($store)->load('items', 'customer')));

    // Queue is `sync` under test, so the job has already run.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send')
        && $request['message']['token'] === 'phone-token'
        && str_contains($request['message']['notification']['title'], 'New order'));
});
