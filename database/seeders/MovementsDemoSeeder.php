<?php

namespace Database\Seeders;

use App\Actions\Movements\RecordInitialAssignment;
use App\Actions\Movements\TransferAssets;
use App\Enums\MovementType;
use App\Models\Asset;
use App\Models\Holder;
use App\Models\Movement;
use App\Models\Room;
use Illuminate\Database\Seeder;

/**
 * Mouvements de démonstration : l'affectation initiale de chaque bien (RG-22), puis quelques transferts.
 * Idempotent : un bien qui a déjà son mouvement initial n'en reçoit pas un second, et les transferts
 * ne sont créés que sur une base qui n'en contient aucun. Jamais chargé en production.
 */
class MovementsDemoSeeder extends Seeder
{
    private const int TRANSFERS = 8;

    public function run(): void
    {
        $record = app(RecordInitialAssignment::class);

        // Pas de journal pour la reprise : les biens de démonstration sont eux-mêmes créés hors journal.
        activity()->withoutLogs(function () use ($record): void {
            Asset::query()->withoutGlobalScopes()
                ->whereDoesntHave('movements', fn ($query) => $query->withoutGlobalScopes()->where('type', MovementType::Initial))
                ->orderBy('id')
                ->each(fn (Asset $asset) => $record($asset));
        });

        if (Movement::query()->withoutGlobalScopes()->where('type', '!=', MovementType::Initial)->exists()) {
            return;
        }

        $this->transfers();
    }

    private function transfers(): void
    {
        $holders = Holder::query()->where('is_active', true)->orderBy('id')->pluck('id')->all();

        $assets = Asset::query()->withoutGlobalScopes()
            ->whereNull('disposed_at')
            ->whereDate('acquired_on', '<', '2026-06-01')
            ->orderBy('id')
            ->get()
            ->filter(fn (Asset $asset): bool => $asset->id % 37 === 0)
            ->take(self::TRANSFERS)
            ->values();

        foreach ($assets as $index => $asset) {
            $target = [
                'site_id' => $asset->site_id,
                'department_id' => $asset->department_id,
                'service_id' => $asset->service_id,
                'room_id' => $asset->room_id,
                'holder_id' => $asset->holder_id,
                'moved_on' => today()->subDays(15 + $index * 9)->toDateString(),
                'reason' => 'Changement de responsable',
            ];

            if ($index % 2 === 0 && $holders !== []) {
                $target['holder_id'] = collect($holders)->first(fn (int $id): bool => $id !== $asset->holder_id);
            } else {
                $room = Room::query()->withoutGlobalScopes()
                    ->where('is_active', true)->where('site_id', $asset->site_id)->where('id', '!=', $asset->room_id ?? 0)
                    ->orderBy('id')->first();
                $target['room_id'] = $room === null ? $asset->room_id : $room->id;
                $target['reason'] = 'Déménagement';
            }

            if (($target['holder_id'] ?? null) === $asset->holder_id && $target['room_id'] === $asset->room_id) {
                continue;
            }

            app(TransferAssets::class)([$asset], $target);
        }
    }
}
