<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\BunnyCdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Image upload for product photos and the store logo. Returns the stored path
 * — which is what gets saved on the record — plus a URL for previewing.
 */
class UploadController extends Controller
{
    /** Folders a seller may write into. */
    public const FOLDERS = ['products', 'vendors'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:5120'],
            'folder' => ['required', Rule::in(self::FOLDERS)],
        ]);

        $prefix = trim((string) config('services.bunnycdn.prefix'), '/');
        // Each store writes under its own folder, so one seller's uploads can
        // never collide with or overwrite another's.
        $storeId = (int) $request->user()->vendor_id;
        $directory = trim("{$prefix}/{$data['folder']}/{$storeId}", '/');

        try {
            $path = BunnyCdn::upload($request->file('file'), $directory);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Upload failed. Please try again.'], 502);
        }

        return response()->json([
            'path' => $path,
            'url' => BunnyCdn::url($path),
        ], 201);
    }
}
