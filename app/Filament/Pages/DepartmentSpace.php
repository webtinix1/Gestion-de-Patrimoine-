<?php

namespace App\Filament\Pages;

use App\Filament\Actions\AssetReportActions;
use App\Models\Asset;
use App\Models\User;
use App\Services\Reporting\DashboardData;
use App\Support\DefaultRoles;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * E42 : espace du responsable de département. Vue d'ensemble de son périmètre (biens, prêts, signalements,
 * inventaires où il est désigné) et raccourcis vers les actions que son rôle permet. Rien n'y sort du périmètre
 * (RG-81, RG-82).
 */
class DepartmentSpace extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Accueil';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Mon espace';

    protected static ?string $slug = 'espace-responsable';

    protected string $view = 'filament.pages.department-space';

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        // Le responsable de département retrouve son espace sur le tableau de bord.
        return self::canAccess() && ! ($user instanceof User && Dashboard::isDepartmentHead($user));
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('viewAny', Asset::class)) {
            return false;
        }

        return $user->hasRole(DefaultRoles::DEPARTMENT_HEAD) || $user->perimeterDepartmentIds() !== null;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Mon espace';
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return app(DashboardData::class)->departmentSpace($user);
    }

    protected function getHeaderActions(): array
    {
        return [
            AssetReportActions::create(),
        ];
    }
}
