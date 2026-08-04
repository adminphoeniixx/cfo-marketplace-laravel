<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BunnyCdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class UploadController extends Controller
{
    /** Folders the admin UI is allowed to write into. */
    public const FOLDERS = ['products', 'categories', 'vendors'];

    /**
     * Push an image to BunnyCDN and hand back the stored path plus a URL the
     * browser can render straight away.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:5120'],
            'folder' => ['required', Rule::in(self::FOLDERS)],
        ]);

        $prefix = trim((string) config('services.bunnycdn.prefix'), '/');
        $directory = $prefix === '' ? $data['folder'] : "{$prefix}/{$data['folder']}";

        try {
            $path = BunnyCdn::upload($request->file('file'), $directory);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Upload failed. Please try again.'], 502);
        }

        return response()->json([
            'path' => $path,
            'url' => BunnyCdn::url($path),
        ]);
    }
}
