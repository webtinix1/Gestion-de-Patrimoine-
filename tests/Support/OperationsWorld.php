<?php

namespace Tests\Support;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetCondition;
use App\Models\Department;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultRoles;

/**
 * Décor commun aux tests des prêts, de la maintenance et des signalements (lot 8).
 */
final class OperationsWorld
{
    public User $manager;

    public Site $site;

    public Department $louange;

    public Department $jeunesse;

    public AssetCondition $neuf;

    public AssetCondition $bon;

    public AssetCondition $moyen;

    public AssetCondition $aReparer;

    public AssetCondition $horsService;

    public static function create(): self
    {
        $world = new self;
        $world->manager = User::factory()->role(DefaultRoles::MANAGER)->create();
        $world->site = Site::factory()->create(['name' => 'Siège']);
        $world->louange = Department::factory()->create(['name' => 'Louange']);
        $world->jeunesse = Department::factory()->create(['name' => 'Jeunesse']);
        $world->neuf = AssetCondition::factory()->create(['label' => 'Neuf', 'sort' => 1]);
        $world->bon = AssetCondition::factory()->create(['label' => 'Bon état', 'sort' => 3]);
        $world->moyen = AssetCondition::factory()->create(['label' => 'État moyen', 'sort' => 4]);
        $world->aReparer = AssetCondition::factory()->create(['label' => 'À réparer', 'sort' => 5]);
        $world->horsService = AssetCondition::factory()->create(['label' => 'Hors service', 'sort' => 6]);

        return $world;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function asset(array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'site_id' => $this->site->id,
            'department_id' => $this->louange->id,
            'asset_condition_id' => $this->bon->id,
            'status' => AssetStatus::InService,
            ...$attributes,
        ])->fresh();
    }

    public function head(Department $department): User
    {
        $head = User::factory()->role(DefaultRoles::DEPARTMENT_HEAD)->create();
        $head->departments()->attach($department);

        return $head;
    }

    public function restrictedManager(Department $department): User
    {
        $user = User::factory()->role(DefaultRoles::MANAGER)->create();
        $user->departments()->attach($department);

        return $user;
    }
}
