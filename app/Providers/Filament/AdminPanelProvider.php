<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Theme;
use App\Http\Controllers\AssetPhotoController;
use App\Http\Controllers\AssetReportPhotoController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public const array NAVIGATION_GROUPS = [
        'Accueil',
        'Patrimoine',
        'Opérations',
        'Immobilisations',
        'Pilotage',
        'Structure',
        'Administration',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->brandName('Patrimoine')
            ->colors(Theme::colors())
            ->font('Archivo', url: asset('fonts/fonts.css'), provider: LocalFontProvider::class)
            ->monoFont('Martian Mono', url: asset('fonts/fonts.css'), provider: LocalFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups(self::NAVIGATION_GROUPS)
            // RG-66 : les fichiers ne sortent que par ces routes, derrière l'authentification du panneau.
            ->authenticatedRoutes(function (): void {
                Route::get('documents/{document}/fichier', DocumentFileController::class)->name('documents.file');
                Route::get('biens/{asset}/photo', AssetPhotoController::class)->name('assets.photo');
                Route::get('signalements/{report}/photo', AssetReportPhotoController::class)->name('reports.photo');
            })
            ->darkMode(false)
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordIsChanged::class,
            ]);
    }
}
