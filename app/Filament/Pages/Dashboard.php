<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Reporting\DashboardData;
use App\Support\DefaultRoles;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * E02 : tableau de bord. Les chiffres viennent de PatrimonyFigures, comme les rapports, et sont limités au
 * périmètre de l'utilisateur. Le responsable de département arrive sur son espace (E42).
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Tableau de bord';

    protected static string|\UnitEnum|null $navigationGroup = 'Accueil';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.dashboard';

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ! self::isDepartmentHead($user);
    }

    public static function isDepartmentHead(User $user): bool
    {
        return $user->hasRole(DefaultRoles::DEPARTMENT_HEAD);
    }

    public function mount(): void
    {
        $user = auth()->user();

        if ($user instanceof User && self::isDepartmentHead($user) && DepartmentSpace::canAccess()) {
            $this->redirect(DepartmentSpace::getUrl());
        }
    }

    public function getWidgets(): array
    {
        return [];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Tableau de bord';
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return app(DashboardData::class)->overview($user);
    }

    public function getGreeting(): string
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->first_name;
    }
}
