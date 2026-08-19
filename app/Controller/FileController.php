<?php

declare(strict_types=1);

namespace App\Controller;

use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Routing\Attribute\Route;
use Framework\Routing\Attribute\Get;
use Framework\Routing\Attribute\Post;
use Framework\Storage\FileStorage;

/**
 * Example of the FileStorage flow using the 'private' driver — files land
 * under storage/app/uploads (outside the document root), so this controller
 * is the only way to reach them. If you want publicly-servable uploads
 * instead, resolve 'storage.public' from the container in the constructor
 * (Container::get('storage.public')) rather than type-hinting FileStorage —
 * see config/filesystem.php.
 */
#[Route('/files')]
class FileController
{
    public function __construct(private readonly FileStorage $storage)
    {
    }

    #[Post('/')]
    public function upload(Request $request): Response
    {
        $file = $request->file('file');
        if ($file === null) {
            return Response::json(['error' => 'No file uploaded under the "file" field.'], 422);
        }

        // ValidationException from store() bubbles up to the global Handler
        // and renders as a 422 JSON response — no try/catch needed here.
        $path = $this->storage->store($file, subdir: 'uploads');

        // Persist $path in your DB against the owning record (user, post, etc.)
        // so you know who it belongs to before serving it back in show().
        return Response::json(['path' => $path], 201);
    }

    #[Get('/{path}')]
    public function show(Request $request, string $path): Response
    {
        // Add your own auth/ownership check here before serving — that's the
        // whole reason this lives behind a controller instead of a static URL.
        $fullPath = $this->storage->path($path);

        if (!is_file($fullPath)) {
            return Response::json(['error' => 'File not found.'], 404);
        }

        return new Response(
            (string) file_get_contents($fullPath),
            200,
            ['Content-Type' => (string) mime_content_type($fullPath)]
        );
    }
}
