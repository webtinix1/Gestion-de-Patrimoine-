<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RG-14, RG-66 : la photo d'un bien n'est servie qu'à un utilisateur qui peut consulter ce bien.
 */
class AssetPhotoController extends Controller
{
    public function __invoke(int $asset): StreamedResponse
    {
        // Hors périmètre : le bien n'existe pas pour l'utilisateur (scope global).
        $asset = Asset::query()->findOrFail($asset);

        Gate::authorize('view', $asset);

        $disk = Storage::disk('local');

        abort_unless($asset->photo_path !== null && $disk->exists($asset->photo_path), 404);

        return $disk->response($asset->photo_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
