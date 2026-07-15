<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class FileManagerController extends Controller
{
    protected string $disk = 'public';

    public function index(Request $request)
{
    \Log::info('FileManager path:', [$request->get('path')]);

    $path = $request->get('path', '/');

    // Normalize path
    $path = trim($path, '/');
    $path = $path === '' ? null : $path;

    $folders = Storage::disk($this->disk)->directories($path);
    $files   = Storage::disk($this->disk)->files($path);

    $data = [];

    // ✅ FOLDERS FIRST
    foreach ($folders as $folder) {
        $data[] = [
            'id'   => '/' . $folder,
            'type' => 'folder',
            'name' => basename($folder),
            'size' => 0,
            'date' => now()->toISOString(),
        ];
    }

    // ✅ FILES
    foreach ($files as $file) {
        // optional: hide dot-files
        if (str_starts_with(basename($file), '.')) {
            continue;
        }

        $data[] = [
            'id'      => '/' . $file,
            'type'    => 'file',
            'name'    => basename($file),
            'size'    => Storage::disk($this->disk)->size($file),
            'date'    => date('c', Storage::disk($this->disk)->lastModified($file)),
            // optional thumbnail
            'preview' => Storage::disk($this->disk)->url($file),
        ];
    }

    return response()->json($data);
}

    public function upload(Request $request)
    {
        $path = trim($request->path ?? '/', '/');

        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $request->file('file')->store($path, $this->disk);

        return response()->json(['success' => true]);
    }

    public function createFolder(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        Storage::disk($this->disk)->makeDirectory(trim($request->path, '/'));

        return response()->json(['success' => true]);
    }

    public function delete(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = trim($request->path, '/');

        if (Storage::disk($this->disk)->exists($path)) {
            Storage::disk($this->disk)->delete($path);
            Storage::disk($this->disk)->deleteDirectory($path);
        }

        return response()->json(['success' => true]);
    }
}
