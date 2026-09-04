<?php

use App\Models\User;
use App\Models\Vendor;
use App\Notifications\VendorRegistered;
use Illuminate\Support\Facades\Notification;

/*
| The public door into selling here.
|
| The seller app has had `POST /api/seller/register` from the start; a shopper
| tapping "Sell with us" had nowhere to land, because the only web signup makes
| a staff login.
*/

test('anybody can open the apply page', function () {
    $this->get(route('sell'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('sell/Register')->has('store'));
});

test('applying creates a store waiting for approval, and signs the seller in', function () {
    Notification::fake();

    $this->post(route('sell.store'), [
        'store_name' => 'Meera Textiles',
        'name' => 'Meera Iyer',
        'email' => 'meera@example.com',
        'phone' => '9876543210',
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertRedirect(route('seller.pending'));

    $vendor = Vendor::firstOrFail();
    $user = User::where('email', 'meera@example.com')->firstOrFail();

    // Pending, always. There is no path through the form that skips approval.
    expect($vendor->status)->toBe('pending')
        ->and($vendor->name)->toBe('Meera Textiles')
        ->and($vendor->city)->toBe('Chennai')
        ->and($user->role)->toBe('vendor')
        ->and($user->vendor_id)->toBe($vendor->id)
        ->and(auth()->id())->toBe($user->id);
});

test('the marketplace is told when somebody applies', function () {
    Notification::fake();

    // Somebody has to be there to hear it: `Notifier` addresses staff who hold
    // the section, and an empty marketplace notifies nobody.
    adminUser();

    $this->post(route('sell.store'), [
        'store_name' => 'Meera Textiles',
        'name' => 'Meera Iyer',
        'email' => 'meera@example.com',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertRedirect();

    Notification::assertSentTimes(VendorRegistered::class, 1);
});

test('an email already in use is refused', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('sell.store'), [
        'store_name' => 'Meera Textiles',
        'name' => 'Meera Iyer',
        'email' => 'taken@example.com',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertSessionHasErrors('email');

    expect(Vendor::count())->toBe(0);
});

test('a pending seller sees the waiting screen, not the panel', function () {
    [$seller] = actingAsSeller(['status' => 'pending']);

    $this->actingAs($seller)
        ->get(route('seller.pending'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sell/Pending')
            ->where('store.status', 'pending'));

    // The panel itself stays shut until somebody approves the store.
    $this->actingAs($seller)->get('/seller/products')->assertForbidden();
});

test('the app and the web form create the same thing', function () {
    Notification::fake();

    $this->postJson(route('api.seller.register'), [
        'store_name' => 'App Store',
        'name' => 'From App',
        'email' => 'app@example.com',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
        'device_name' => 'Pixel 8',
    ])->assertCreated();

    $this->post(route('sell.store'), [
        'store_name' => 'Web Store',
        'name' => 'From Web',
        'email' => 'web@example.com',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertRedirect();

    $fromApp = Vendor::where('name', 'App Store')->firstOrFail();
    $fromWeb = Vendor::where('name', 'Web Store')->firstOrFail();

    // One action behind both doors, so neither can quietly grant more than
    // the other.
    expect($fromApp->status)->toBe($fromWeb->status)
        ->and(User::where('email', 'app@example.com')->value('role'))
        ->toBe(User::where('email', 'web@example.com')->value('role'));
});
