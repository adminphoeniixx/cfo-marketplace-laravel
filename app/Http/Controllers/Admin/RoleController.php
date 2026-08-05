<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        $permissions = Roles::permissions();
        $counts = Roles::memberCounts();

        return Inertia::render('admin/roles/Index', [
            'roles' => collect(Roles::ALL)
                ->map(fn (array $role, string $key) => [
                    'value' => $key,
                    'label' => $role['label'],
                    'description' => $role['description'],
                    'editable' => Roles::isEditable($key),
                    'sections' => $permissions[$key] ?? [],
                    'members' => $counts[$key] ?? 0,
                ])
                ->values(),
            'sections' => collect(Roles::SECTIONS)
                ->map(fn (array $section, string $key) => [
                    'value' => $key,
                    'label' => $section['label'],
                    'group' => $section['group'],
                ])
                ->values(),
        ]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        abort_unless(Roles::isEditable($role), 404);

        $data = $request->validate([
            'sections' => ['present', 'array'],
            'sections.*' => [Rule::in(Roles::sectionKeys())],
        ]);

        Roles::save($role, $data['sections']);

        return back()->with('success', Roles::label($role).' access updated.');
    }

    /**
     * Drop a role back to the access it shipped with.
     */
    public function reset(string $role): RedirectResponse
    {
        abort_unless(Roles::isEditable($role), 404);

        Roles::save($role, Roles::DEFAULTS[$role] ?? []);

        return back()->with('success', Roles::label($role).' reset to the default access.');
    }
}
