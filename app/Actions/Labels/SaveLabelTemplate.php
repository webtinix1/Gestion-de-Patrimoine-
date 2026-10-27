<?php

namespace App\Actions\Labels;

use App\Enums\LabelSheetLayout;
use App\Models\LabelTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Enregistre un modèle d'étiquette. Il y a un modèle par défaut par type de support :
 * un pour les planches, un pour les rouleaux (l'écran d'impression le propose en premier).
 */
class SaveLabelTemplate
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data, ?LabelTemplate $template = null): LabelTemplate
    {
        $template === null
            ? Gate::authorize('create', LabelTemplate::class)
            : Gate::authorize('update', $template);

        return DB::transaction(function () use ($data, $template): LabelTemplate {
            $template ??= new LabelTemplate;
            $template->fill($data);

            $sameSupport = fn () => LabelTemplate::query()
                ->whereIn('sheet_layout', $this->layoutsLike($template->sheet_layout))
                ->when($template->exists, fn ($query) => $query->whereKeyNot($template->getKey()));

            // Un support doit toujours avoir un modèle par défaut : le premier modèle l'est d'office.
            if (! $template->is_default && ! $sameSupport()->where('is_default', true)->exists()) {
                $template->is_default = true;
            }

            $template->save();

            if ($template->is_default) {
                $sameSupport()->where('is_default', true)->update(['is_default' => false]);
            }

            return $template;
        });
    }

    /**
     * @return list<string>
     */
    private function layoutsLike(LabelSheetLayout $layout): array
    {
        return collect(LabelSheetLayout::cases())
            ->filter(fn (LabelSheetLayout $case): bool => $case->isSheet() === $layout->isSheet())
            ->map(fn (LabelSheetLayout $case): string => $case->value)
            ->values()
            ->all();
    }
}
