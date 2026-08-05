<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\TeamMemberChanged;
use App\Services\Notifier;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $members = User::query()
            ->with('vendor:id,name')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
            ))
            ->when($request->string('role')->toString(), fn ($query, $role) => $query->where('role', $role))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where(
                'is_active',
                $status === 'active'
            ))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                ...$user->only(['id', 'name', 'email', 'phone', 'role', 'vendor_id', 'is_active']),
                'role_label' => Roles::label($user->role),
                'vendor_name' => $user->vendor?->name,
                'last_seen_at' => $user->updated_at?->toDateTimeString(),
                'is_self' => $user->id === $request->user()->id,
            ]);

        return Inertia::render('admin/team/Index', [
            'members' => $members,
            'filters' => $request->only(['search', 'role', 'status']),
            'roles' => collect(Roles::ALL)
                ->map(fn (array $role, string $key) => ['value' => $key, 'label' => $role['label']])
                ->values(),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name']),
            // A manager with team access must not be able to mint new admins.
            'canManageAdmins' => $request->user()->isAdmin(),
            'counts' => [
                'all' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $this->guardTargetRole($request, $data['role']);

        $user = new User;
        $user->forceFill([
            ...$data,
            'email_verified_at' => now(),
        ])->save();

        Notifier::send(TeamMemberChanged::for($user, 'added', $request->user()), $request->user());

        return back()->with('success', "{$user->name} can now sign in.");
    }

    public function update(Request $request, User $member): RedirectResponse
    {
        $this->guardTarget($request, $member);

        $data = $this->validated($request, $member);

        $this->guardTargetRole($request, $data['role']);

        if ($member->id === $request->user()->id && $data['role'] !== $member->role) {
            return back()->with('error', 'You cannot change your own role.');
        }

        // Password is optional on edit — an empty box means "leave it alone".
        if (($data['password'] ?? null) === null) {
            unset($data['password']);
        }

        if ($this->wouldStrandThePanel($member, $data['role'], (bool) $data['is_active'])) {
            return back()->with('error', 'This is the last active admin — promote someone else first.');
        }

        $roleChanged = $member->role !== $data['role'];

        $member->forceFill($data)->save();

        if ($roleChanged) {
            Notifier::send(TeamMemberChanged::for($member, 'role-changed', $request->user()), $request->user());
        }

        return back()->with('success', "{$member->name} updated.");
    }

    public function toggle(Request $request, User $member): RedirectResponse
    {
        $this->guardTarget($request, $member);

        if ($member->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($this->wouldStrandThePanel($member, $member->role, ! $member->is_active)) {
            return back()->with('error', 'This is the last active admin — promote someone else first.');
        }

        $member->forceFill(['is_active' => ! $member->is_active])->save();

        if (! $member->is_active) {
            Notifier::send(TeamMemberChanged::for($member, 'deactivated', $request->user()), $request->user());
        }

        return back()->with('success', $member->is_active
            ? "{$member->name} can sign in again."
            : "{$member->name} has been deactivated.");
    }

    public function destroy(Request $request, User $member): RedirectResponse
    {
        $this->guardTarget($request, $member);

        if ($member->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($this->wouldStrandThePanel($member, $member->role, false)) {
            return back()->with('error', 'This is the last active admin — promote someone else first.');
        }

        // Order events name whoever made the change, so deleting would erase
        // the trail. Deactivating keeps the history readable.
        if (OrderEvent::where('user_id', $member->id)->exists()) {
            return back()->with('error', 'This member has order history — deactivate them instead.');
        }

        $removed = TeamMemberChanged::for($member, 'removed', $request->user());

        $member->delete();

        Notifier::send($removed, $request->user());

        return back()->with('success', "{$member->name} removed.");
    }

    /**
     * Admin accounts may only be touched by another admin.
     */
    private function guardTarget(Request $request, User $member): void
    {
        abort_if(
            $member->isAdmin() && ! $request->user()->isAdmin(),
            403,
            'Only admins can manage admin accounts.'
        );
    }

    /**
     * Blocks a non-admin from handing out the admin role.
     */
    private function guardTargetRole(Request $request, string $role): void
    {
        abort_if(
            $role === Roles::ADMIN && ! $request->user()->isAdmin(),
            403,
            'Only admins can grant the admin role.'
        );
    }

    /**
     * Whether applying this role/status would leave nobody able to sign in as
     * an admin — the one change the panel cannot recover from on its own.
     */
    private function wouldStrandThePanel(User $member, string $role, bool $active): bool
    {
        if (! $member->isAdmin()) {
            return false;
        }

        // Still an active admin afterwards, so nothing is lost.
        if ($role === Roles::ADMIN && $active) {
            return false;
        }

        return ! User::where('role', Roles::ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($member->id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $member = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:180',
                Rule::unique('users', 'email')->ignore($member?->id),
            ],
            'role' => ['required', Rule::in(Roles::keys())],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'phone' => ['nullable', 'string', 'max:25'],
            'is_active' => ['boolean'],
            'password' => [$member ? 'nullable' : 'required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        // A vendor login is meaningless without the store it belongs to, and
        // any other role must not carry one around.
        if ($data['role'] === 'vendor' && empty($data['vendor_id'])) {
            throw ValidationException::withMessages([
                'vendor_id' => 'Pick the store this vendor signs in for.',
            ]);
        }

        if ($data['role'] !== 'vendor') {
            $data['vendor_id'] = null;
        }

        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['password'] = $data['password'] ?? null;

        return $data;
    }
}
