@php
    use App\Enums\InventoryLineResult;

    $inventory = $this->inventory();
    $counters = $this->getCounters();
    $sections = $this->getSections();
    $closed = $inventory->isClosed();
    $card = 'background:#fff;border:1px solid #D7DBE1;border-radius:12px';
    $th = 'padding:10px 14px;border-bottom:2px solid #B9BFC8;text-align:left;font-size:11px;color:#686868;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap';
    $td = 'padding:10px 14px;border-bottom:1px solid #E9ECF0;vertical-align:middle';
    $place = fn ($department, $room): string => collect([$department?->name, $room?->name])->filter()->join(' · ') ?: '—';
@endphp
<x-filament-panels::page>
    <section style="{{ $card }};padding:18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;text-align:center">
        @foreach ([
            ['Biens attendus', $counters['expected'], 'background:#FAFBFC'],
            ['Présents', $counters['present'], 'background:#E4F2EA;color:#1E6E43'],
            ['Manquants', $counters['missing'], 'background:#FBE7E5;color:#A8261B'],
            ['Non enregistrés', $counters['unregistered'], 'background:#E6EBF8;color:#2F4A9E'],
            ['Autre affectation', $counters['other_assignment'], 'background:#FBF0D9;color:#8A5A00'],
            ['État différent', $counters['condition_mismatch'], 'background:#FBF0D9;color:#8A5A00'],
            ['Hors périmètre', $counters['out_of_scope'], 'background:#E6EBF8;color:#2F4A9E'],
            ['Prêtés ou en maintenance', $counters['justified'], 'background:#E9ECF0;color:#4A5560'],
            ['Non vérifiés', $counters['pending'], 'background:#E9ECF0;color:#4A5560'],
        ] as [$label, $value, $style])
            <div style="padding:12px;border-radius:8px;{{ $style }}">
                <div class="pat-mono" style="font-size:24px;font-weight:800">{{ $value }}</div>
                <div style="font-size:12px;font-weight:600">{{ $label }}</div>
            </div>
        @endforeach
    </section>

    @unless ($closed)
        <x-filament::callout color="info">
            <x-slot name="description">Les régularisations (transfert, mise à jour d'état, création de fiche, sortie pour perte) se font depuis ce rapport après la clôture : la clôture ne modifie aucune fiche (RG-48).</x-slot>
        </x-filament::callout>
    @endunless

    @foreach ($sections as $key => $section)
        @continue($section['lines']->isEmpty())
        <section style="{{ $card }}">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;padding:16px 18px 10px">
                <h2 style="font-size:16px;font-weight:600;display:flex;align-items:center;gap:10px">
                    <x-filament::badge :color="$section['color']">{{ $section['lines']->count() }}</x-filament::badge>
                    {{ $section['label'] }}
                </h2>
            </div>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">N°</th>
                            <th style="{{ $th }}">Désignation</th>
                            <th style="{{ $th }}">Enregistré</th>
                            <th style="{{ $th }}">Constaté</th>
                            <th style="{{ $th }}">Pointé par</th>
                            <th style="{{ $th }}">Régularisation</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['lines'] as $line)
                            <tr wire:key="report-line-{{ $line->id }}">
                                <td style="{{ $td }}">
                                    @if ($url = $this->assetUrl($line->asset))
                                        <a href="{{ $url }}" class="pat-mono" style="font-weight:600;font-size:12px;color:#0B5D6B">{{ $line->asset->number }}</a>
                                    @else
                                        <x-filament::badge color="info">Sans étiquette</x-filament::badge>
                                    @endif
                                </td>
                                <td style="{{ $td }}">{{ $line->asset?->label() ?? $line->description }}</td>
                                <td style="{{ $td }}">
                                    @if ($line->asset_id)
                                        {{ $key === 'condition_mismatch' ? ($line->expectedCondition->label ?? '—') : $place($line->expectedDepartment, $line->expectedRoom) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="{{ $td }}">
                                    @switch($key)
                                        @case('condition_mismatch')
                                            {{ $line->foundCondition->label ?? '—' }}
                                            @break
                                        @case('justified')
                                            {{ $line->expected_status?->getLabel() ?? '—' }} au démarrage
                                            @break
                                        @case('missing')
                                            —
                                            @break
                                        @default
                                            {{ $line->foundDepartment || $line->foundRoom ? $place($line->foundDepartment ?? $line->expectedDepartment, $line->foundRoom) : ($line->foundSite->name ?? '—') }}
                                            @if ($line->foundCondition)
                                                <div style="font-size:12px;color:#686868">{{ $line->foundCondition->label }}</div>
                                            @endif
                                    @endswitch
                                </td>
                                <td style="{{ $td }}">{{ $line->checker?->fullName() ?? 'Application' }}</td>
                                <td style="{{ $td }}">
                                    @if ($line->resolution)
                                        <x-filament::badge color="success">{{ $this->resolutionLabel($line) }}</x-filament::badge>
                                        @if ($line->resolution_notes)
                                            <div style="font-size:12px;color:#686868">{{ $line->resolution_notes }}</div>
                                        @endif
                                    @elseif ($closed)
                                        <div style="display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center">
                                            @switch($line->result)
                                                @case(InventoryLineResult::Missing)
                                                    @if ($disposal = $this->disposalUrl($line))
                                                        <a href="{{ $disposal }}" style="font-size:14px;font-weight:600;color:#0B5D6B">Déclarer perdu</a>
                                                    @endif
                                                    {{ ($this->foundAction)(['line' => $line->id]) }}
                                                    @break
                                                @case(InventoryLineResult::OtherAssignment)
                                                @case(InventoryLineResult::OutOfScope)
                                                    {{ ($this->transferAction)(['line' => $line->id]) }}
                                                    @break
                                                @case(InventoryLineResult::ConditionMismatch)
                                                    {{ ($this->conditionAction)(['line' => $line->id]) }}
                                                    @break
                                                @case(InventoryLineResult::Unregistered)
                                                    {{ ($this->createAssetAction)(['line' => $line->id]) }}
                                                    @break
                                                @default
                                                    <span style="color:#686868">—</span>
                                            @endswitch
                                        </div>
                                    @else
                                        <span style="color:#686868">Après la clôture</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    @if (($observations = $this->getObservations())->isNotEmpty())
        <section style="{{ $card }};padding:18px;display:flex;flex-direction:column;gap:10px">
            <h2 style="font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Observations des participants</h2>
            @foreach ($observations as $line)
                <p style="margin:0;font-size:14px;line-height:1.5">
                    <strong>{{ $line->checker?->fullName() ?? '—' }}</strong>{{ $line->asset ? ' · '.$line->asset->number : '' }} : {{ $line->notes }}
                </p>
            @endforeach
        </section>
    @endif

    <section style="{{ $card }};padding:18px;display:flex;flex-direction:column;gap:12px;border:2px solid #0B5D6B">
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
            <h2 style="font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Validation</h2>
            @if ($closed)
                <x-filament::badge color="success">Clôturé le {{ $inventory->closed_at?->format('d/m/Y') }} par {{ $inventory->closer?->fullName() }}</x-filament::badge>
            @else
                <x-filament::badge :color="$inventory->status->getColor()">{{ $inventory->status->getLabel() }}</x-filament::badge>
            @endif
        </div>
        <p style="margin:0;font-size:14px;color:#686868">Le résultat validé est figé et conservé dans l'historique. Les régularisations ultérieures restent tracées sur chaque fiche.</p>
        @if ($closed && $inventory->closing_comment)
            <p style="margin:0;font-size:14px">{{ $inventory->closing_comment }}</p>
        @endif
        @if ($inventory->status === \App\Enums\InventoryStatus::ToValidate)
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                {{ $this->closeAction }}
                {{ $this->declareMissingAction }}
            </div>
        @elseif ($inventory->status === \App\Enums\InventoryStatus::InProgress)
            <p style="margin:0;font-size:14px">Saisie en cours : la validation sera possible quand la saisie sera terminée.</p>
        @endif
    </section>
</x-filament-panels::page>
