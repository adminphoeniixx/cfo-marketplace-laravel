<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Roles;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot open the role matrix', function () {
    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
});

test('only admins can open the role matrix', function () {
    actingAsAdmin(['role' => 'manager']);

    $this->get(route('admin.roles.index'))->assertForbidden();

    actingAsAdmin();

    $this->get(route('admin.roles.index'))->assertOk();
});

test('roles are listed with their sections and member counts', function () {
    actingAsAdmin();
    User::factory()->count(2)->create(['role' => 'staff']);

    $this->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/Index')
            ->has('roles', 4)
            ->where('roles.0.value', 'admin')
            ->where('roles.0.editable', false)
            // Admin always holds the lot, whatever is stored.
            ->where('roles.0.sections', Roles::sectionKeys())
            ->where('roles.2.value', 'staff')
            ->where('roles.2.members', 2)
            ->has('sections', count(Roles::SECTIONS))
        );
});

test('editing a role changes what its members can open', function () {
    actingAsAdmin();
    $staff = User::factory()->create(['role' => 'staff']);

    // Staff ship without catalog access.
    $this->actingAs($staff)->get(route('admin.products.index'))->assertForbidden();

    $this->actingAs(adminUser());
    $this->put(route('admin.roles.update', 'staff'), [
        'sections' => ['orders', 'products'],
    ])->assertSessionHas('success');

    expect(Roles::forRole('staff'))->toBe(['orders', 'products']);

    $this->actingAs($staff);
    $this->get(route('admin.products.index'))->assertOk();
    // …and what was taken away is now closed.
    $this->get(route('admin.customers.index'))->assertForbidden();
});

test('clearing every section leaves only the dashboard', function () {
    actingAsAdmin();
    $staff = User::factory()->create(['role' => 'staff']);

    $this->put(route('admin.roles.update', 'staff'), ['sections' => []])
        ->assertSessionHas('success');

    $this->actingAs($staff);
    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('admin.orders.index'))->assertForbidden();
});

test('the admin role cannot be edited', function () {
    actingAsAdmin();

    $this->put(route('admin.roles.update', 'admin'), ['sections' => ['orders']])
        ->assertNotFound();

    expect(Roles::forRole('admin'))->toBe(Roles::sectionKeys())
        ->and(Setting::where('key', 'role_permissions.admin')->exists())->toBeFalse();
});

test('unknown sections are rejected', function () {
    actingAsAdmin();

    $this->put(route('admin.roles.update', 'staff'), [
        'sections' => ['orders', 'launch-nukes'],
    ])->assertSessionHasErrors('sections.1');

    expect(Roles::forRole('staff'))->toBe(Roles::DEFAULTS['staff']);
});

test('a role can be reset to the access it shipped with', function () {
    actingAsAdmin();

    $this->put(route('admin.roles.update', 'staff'), ['sections' => ['taxes']]);
    expect(Roles::forRole('staff'))->toBe(['taxes']);

    $this->post(route('admin.roles.reset', 'staff'))->assertSessionHas('success');

    expect(Roles::forRole('staff'))->toBe(Roles::DEFAULTS['staff']);
});

test('the sidebar only advertises what the role can open', function () {
    actingAsAdmin(['role' => 'staff']);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.sections', Roles::DEFAULTS['staff'])
        );
});
