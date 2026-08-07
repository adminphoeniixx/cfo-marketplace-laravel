<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Extra logins for one store.
 *
 * A vendor is a business and a seller is a person, so a store can have several
 * people signing in — an owner, someone who packs, someone who answers
 * customers. They all see exactly the same store data; the only thing private
 * to each is their own password, devices and notifications.
 *
 * There is no owner/staff distinction inside a store yet: every login here can
 * manage the others. The guards below stop the two ways that could go wrong —
 * removing yourself, or emptying the store of logins entirely.
 */
class TeamController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        return response()->json([
            'data' => User::where('vendor_id', $storeId)
                ->where('role', 'vendor')
                ->orderBy('name')
                ->get()
                ->map(fn (User $u) => $this->shape($u, $request))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:25'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $member = new User;
        $member->forceFill([
            ...$data,
            // Role and store come from the token, never the payload — this is
            // how a seller is stopped from minting a login for another store.
            'role' => 'vendor',
            'vendor_id' => $this->storeId($request),
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        return response()->json(['data' => $this->shape($member, $request)], 201);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $user = $this->findOwnedMember($request, $member);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25'],
        ]);

        $user->forceFill($data)->save();

        return response()->json(['data' => $this->shape($user, $request)]);
    }

    /**
     * Switch a colleague's access off without deleting the person, so their
     * name stays readable on whatever they already did.
     */
    public function toggle(Request $request, int $member): JsonResponse
    {
        $user = $this->findOwnedMember($request, $member);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot deactivate your own login.'], 422);
        }

        if ($user->is_active && $this->wouldEmptyTheStore($request, $user)) {
            return response()->json(['message' => 'This is the store\'s last active login.'], 422);
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        $user->tokens()->delete();

        return response()->json(['data' => $this->shape($user, $request)]);
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        $user = $this->findOwnedMember($request, $member);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot remove your own login.'], 422);
        }

        if ($this->wouldEmptyTheStore($request, $user)) {
            return response()->json(['message' => 'This is the store\'s last active login.'], 422);
        }

        $user->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * A login on any other store is a 404, exactly like every other record.
     */
    private function findOwnedMember(Request $request, int $id): User
    {
        return User::where('vendor_id', $this->storeId($request))
            ->where('role', 'vendor')
            ->findOrFail($id);
    }

    private function wouldEmptyTheStore(Request $request, User $member): bool
    {
        return ! User::where('vendor_id', $this->storeId($request))
            ->where('role', 'vendor')
            ->where('is_active', true)
            ->whereKeyNot($member->id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(User $user, Request $request): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => (bool) $user->is_active,
            'is_you' => $user->id === $request->user()->id,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
