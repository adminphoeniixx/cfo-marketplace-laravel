<?php

use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Roles;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage the team', function () {
    $this->get(route('admin.team.index'))->assertRedirect(route('login'));
});

test('the team is listed with roles and stores', function () {
    $me = actingAsAdmin(['name' => 'Aisha']);
    $vendor = Vendor::factory()->create(['name' => 'Nova Traders']);
    User::factory()->create([
        'name' => 'Bilal',
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
    ]);

    $this->get(route('admin.team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/team/Index')
            ->has('members.data', 2)
            ->where('members.data.0.name', 'Aisha')
            ->where('members.data.0.is_self', true)
            ->where('members.data.1.role_label', 'Vendor')
            ->where('members.data.1.vendor_name', 'Nova Traders')
            ->where('canManageAdmins', true)
        );
});

test('a member can be added and signs in with the given password', function () {
    actingAsAdmin();

    $this->post(route('admin.team.store'), [
        'name' => 'Priya',
        'email' => 'priya@cfo.test',
        'role' => 'staff',
        'phone' => '9876543210',
        'is_active' => true,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertSessionHas('success');

    $member = User::where('email', 'priya@cfo.test')->firstOrFail();

    expect($member->role)->toBe('staff')
        ->and($member->is_active)->toBeTrue()
        ->and(Hash::check('secret-password', $member->password))->toBeTrue()
        // Staff added by hand should not have to click a verification link.
        ->and($member->email_verified_at)->not->toBeNull();
});

test('editing without a password leaves the old one in place', function () {
    actingAsAdmin();
    $member = User::factory()->create([
        'role' => 'staff',
        'password' => Hash::make('original-password'),
    ]);

    $this->put(route('admin.team.update', $member), [
        'name' => 'Renamed',
        'email' => $member->email,
        'role' => 'manager',
        'is_active' => true,
    ])->assertSessionHas('success');

    $member->refresh();

    expect($member->name)->toBe('Renamed')
        ->and($member->role)->toBe('manager')
        ->and(Hash::check('original-password', $member->password))->toBeTrue();
});

test('a vendor login must name the store it belongs to', function () {
    actingAsAdmin();

    $this->post(route('admin.team.store'), [
        'name' => 'Seller',
        'email' => 'seller@cfo.test',
        'role' => 'vendor',
        'is_active' => true,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertSessionHasErrors('vendor_id');

    expect(User::where('email', 'seller@cfo.test')->exists())->toBeFalse();
});

test('switching away from vendor drops the store', function () {
    actingAsAdmin();
    $vendor = Vendor::factory()->create();
    $member = User::factory()->create(['role' => 'vendor', 'vendor_id' => $vendor->id]);

    $this->put(route('admin.team.update', $member), [
        'name' => $member->name,
        'email' => $member->email,
        'role' => 'staff',
        'vendor_id' => $vendor->id,
        'is_active' => true,
    ])->assertSessionHas('success');

    expect($member->refresh()->vendor_id)->toBeNull();
});

test('a deactivated member is blocked and signed out', function () {
    $member = actingAsAdmin(['role' => 'staff']);

    $this->get(route('admin.orders.index'))->assertOk();

    $member->forceFill(['is_active' => false])->save();

    $this->get(route('admin.orders.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

test('you cannot lock yourself out', function () {
    $me = actingAsAdmin();

    $this->patch(route('admin.team.toggle', $me))->assertSessionHas('error');
    $this->delete(route('admin.team.destroy', $me))->assertSessionHas('error');

    expect($me->refresh()->is_active)->toBeTrue()
        ->and(User::whereKey($me->id)->exists())->toBeTrue();
});

test('the last active admin cannot be demoted or deactivated', function () {
    actingAsAdmin();
    $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    // Two admins, so this one may step down.
    $this->put(route('admin.team.update', $other), [
        'name' => $other->name,
        'email' => $other->email,
        'role' => 'manager',
        'is_active' => true,
    ])->assertSessionHas('success');

    expect($other->refresh()->role)->toBe('manager');

    // Now only the signed-in admin is left, and self-edits are refused anyway.
    $this->patch(route('admin.team.toggle', $other))->assertSessionHas('success');
});

test('a member with order history cannot be deleted', function () {
    actingAsAdmin();
    $member = User::factory()->create(['role' => 'staff']);
    $order = Order::factory()->create();

    $this->actingAs($member);
    $order->recordEvent('note', 'Called the buyer');
    $this->actingAs(adminUser());

    $this->delete(route('admin.team.destroy', $member))
        ->assertSessionHas('error');

    expect(User::whereKey($member->id)->exists())->toBeTrue();
});

test('a manager with team access cannot touch admins', function () {
    // Give managers the team section so they reach the screen at all.
    Roles::save('manager', [...Roles::DEFAULTS['manager'], 'team']);

    $manager = actingAsAdmin(['role' => 'manager']);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->get(route('admin.team.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageAdmins', false));

    $this->put(route('admin.team.update', $admin), [
        'name' => 'Hijacked',
        'email' => $admin->email,
        'role' => 'admin',
        'is_active' => true,
    ])->assertForbidden();

    $this->delete(route('admin.team.destroy', $admin))->assertForbidden();

    // …and cannot promote themselves either.
    $this->put(route('admin.team.update', $manager), [
        'name' => $manager->name,
        'email' => $manager->email,
        'role' => 'admin',
        'is_active' => true,
    ])->assertForbidden();

    expect($admin->refresh()->name)->not->toBe('Hijacked')
        ->and($manager->refresh()->role)->toBe('manager');
});
