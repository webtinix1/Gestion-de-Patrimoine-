<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RG-66 : seule voie d'accès aux fichiers. Droit « documents.view » et au moins un objet visible.
 */
class DocumentFileController extends Controller
{
    public function __invoke(Request $request, int $document): StreamedResponse
    {
        // Hors périmètre : le document n'existe pas pour l'utilisateur.
        $document = Document::query()->visible()->findOrFail($document);

        Gate::authorize('view', $document);

        $disk = Storage::disk(DocumentRules::DISK);

        abort_unless($disk->exists($document->path), 404);

        $inline = DocumentRules::isInline($document->mime) && ! $request->boolean('download');

        return $disk->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }
}
