<?php

namespace Tests\Support\Fakes;

use App\Actions\Disposals\RecordDisposal;
use App\Models\Disposal;
use Illuminate\Database\Eloquent\Model;

/*
 * Doublures du contrat avec le lot 9 (ResolveInventoryLine, InventoryLine, InventoryResolution),
 * dans l'espace de noms des tests pour ne jamais entrer en collision avec les vraies classes.
 */

class FakeInventoryLine extends Model
{
    /** @var array<int, self> */
    public static array $lines = [];

    protected $guarded = [];

    public static function make(int $id, int $assetId): self
    {
        $line = new self;
        $line->forceFill(['id' => $id, 'asset_id' => $assetId]);

        return self::$lines[$id] = $line;
    }

    public static function find(mixed $id): ?self
    {
        return self::$lines[(int) $id] ?? null;
    }
}

enum FakeInventoryResolution
{
    case Disposal;
}

class FakeResolveInventoryLine
{
    /** @var list<array{0: mixed, 1: mixed, 2: mixed}> */
    public static array $calls = [];

    public function __invoke(mixed $line, mixed $resolution, mixed $disposal): void
    {
        self::$calls[] = [$line, $resolution, $disposal];
    }
}

class RecordDisposalWithFakeInventory extends RecordDisposal
{
    protected const string INVENTORY_RESOLVER = FakeResolveInventoryLine::class;

    protected const string INVENTORY_LINE_MODEL = FakeInventoryLine::class;

    protected const string INVENTORY_RESOLUTION_ENUM = FakeInventoryResolution::class;
}
