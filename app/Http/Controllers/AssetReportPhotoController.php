<?php

namespace App\Http\Controllers;

use App\Models\AssetReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RG-63, RG-66 : la photo d'un signalement n'est servie qu'à un utilisateur qui peut consulter ce signalement.
 */
class AssetReportPhotoController extends Controller
{
    public function __invoke(int $report): StreamedResponse
    {
        // Hors périmètre : le signalement n'existe pas pour l'utilisateur (scope global via le bien).
        $report = AssetReport::query()->findOrFail($report);

        Gate::authorize('view', $report);

        $disk = Storage::disk('local');

        abort_unless($report->photo_path !== null && $disk->exists($report->photo_path), 404);

        return $disk->response($report->photo_path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
