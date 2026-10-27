<?php

namespace App\Support;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;

/**
 * RG-80 : rôles livrés et leur matrice de droits par défaut (maquette E39).
 * Ordre des colonnes : consulter, créer, modifier, supprimer/sortir, valider, exporter.
 */
final class DefaultRoles
{
    public const string ADMIN = 'Administrateur';

    public const string MANAGER = 'Gestionnaire du patrimoine';

    public const string ACCOUNTANT = 'Comptable';

    public const string DEPARTMENT_HEAD = 'Responsable de département';

    public const string VIEWER = 'Consultation';

    /**
     * @var array<string, string>
     */
    public const array DESCRIPTIONS = [
        self::ADMIN => 'Accès complet, y compris utilisateurs, rôles et paramètres.',
        self::MANAGER => 'Gère les biens et toutes les opérations courantes.',
        self::ACCOUNTANT => 'Paramètre et valide les amortissements et les sorties.',
        self::DEPARTMENT_HEAD => 'Consulte les biens de son département, signale et participe aux inventaires.',
        self::VIEWER => 'Lecture seule sur le périmètre autorisé.',
    ];

    /**
     * @var array<string, array<string, list<int>>>
     */
    private const array MATRIX = [
        self::MANAGER => [
            'assets' => [1, 1, 1, 0, 0, 1],
            'labels' => [1, 1, 1, 0, 0, 1],
            'acquisitions' => [1, 1, 1, 0, 0, 1],
            'movements' => [1, 1, 1, 0, 0, 1],
            'inventories' => [1, 1, 1, 0, 1, 1],
            'loans' => [1, 1, 1, 0, 0, 1],
            'maintenance' => [1, 1, 1, 0, 0, 1],
            'asset_reports' => [1, 1, 1, 0, 1, 1],
            'disposals' => [1, 1, 0, 0, 0, 1],
            'depreciation' => [1, 0, 0, 0, 0, 1],
            'documents' => [1, 1, 1, 0, 0, 1],
            'reporting' => [1, 0, 0, 0, 0, 1],
            'structure' => [1, 0, 0, 0, 0, 0],
            'users' => [0, 0, 0, 0, 0, 0],
            'settings' => [0, 0, 0, 0, 0, 0],
            'activity_log' => [1, 0, 0, 0, 0, 0],
        ],
        self::ACCOUNTANT => [
            'assets' => [1, 0, 0, 0, 0, 1],
            'labels' => [0, 0, 0, 0, 0, 0],
            'acquisitions' => [1, 0, 0, 0, 0, 1],
            'movements' => [1, 0, 0, 0, 0, 1],
            'inventories' => [1, 0, 0, 0, 0, 1],
            'loans' => [1, 0, 0, 0, 0, 0],
            'maintenance' => [1, 0, 0, 0, 0, 1],
            'asset_reports' => [0, 0, 0, 0, 0, 0],
            'disposals' => [1, 0, 0, 0, 1, 1],
            'depreciation' => [1, 1, 1, 0, 1, 1],
            'documents' => [1, 1, 0, 0, 0, 1],
            'reporting' => [1, 0, 0, 0, 0, 1],
            'structure' => [1, 0, 0, 0, 0, 0],
            'users' => [0, 0, 0, 0, 0, 0],
            'settings' => [0, 0, 0, 0, 0, 0],
            'activity_log' => [1, 0, 0, 0, 0, 1],
        ],
        self::DEPARTMENT_HEAD => [
            'assets' => [1, 0, 0, 0, 0, 0],
            'labels' => [0, 0, 0, 0, 0, 0],
            'acquisitions' => [0, 0, 0, 0, 0, 0],
            'movements' => [1, 0, 0, 0, 0, 0],
            'inventories' => [1, 0, 1, 0, 0, 0],
            'loans' => [1, 0, 0, 0, 0, 0],
            'maintenance' => [1, 0, 0, 0, 0, 0],
            'asset_reports' => [1, 1, 0, 0, 0, 0],
            'disposals' => [0, 0, 0, 0, 0, 0],
            'depreciation' => [0, 0, 0, 0, 0, 0],
            'documents' => [1, 0, 0, 0, 0, 0],
            'reporting' => [1, 0, 0, 0, 0, 1],
            'structure' => [0, 0, 0, 0, 0, 0],
            'users' => [0, 0, 0, 0, 0, 0],
            'settings' => [0, 0, 0, 0, 0, 0],
            'activity_log' => [0, 0, 0, 0, 0, 0],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::DESCRIPTIONS);
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        $permissions = [];

        foreach (PermissionModule::cases() as $module) {
            foreach (PermissionAbility::cases() as $ability) {
                $permissions[] = $module->permission($ability);
            }
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        if ($role === self::ADMIN) {
            return self::allPermissions();
        }

        if ($role === self::VIEWER) {
            // Consultation : lecture des modules métier et de la structure, export des rapports.
            $modules = array_slice(PermissionModule::cases(), 0, 13);

            return [
                ...array_map(fn (PermissionModule $module): string => $module->permission(PermissionAbility::View), $modules),
                PermissionModule::Reporting->permission(PermissionAbility::Export),
            ];
        }

        $permissions = [];

        foreach (self::MATRIX[$role] ?? [] as $module => $flags) {
            foreach (PermissionAbility::cases() as $index => $ability) {
                if ($flags[$index] === 1) {
                    $permissions[] = $module.'.'.$ability->value;
                }
            }
        }

        return $permissions;
    }
}
