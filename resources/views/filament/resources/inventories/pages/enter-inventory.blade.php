@php
    $counters = $this->getCounters();
    $lines = $this->getLines();
    $conditions = $this->getConditionOptions();
    $tones = [
        'success' => 'background:#E4F2EA;color:#1E6E43',
        'info' => 'background:#E6EBF8;color:#2F4A9E',
        'warning' => 'background:#FBF0D9;color:#8A5A00',
        'danger' => 'background:#FBE7E5;color:#A8261B',
    ];
    $btn = 'min-height:40px;padding:0 12px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;';
@endphp
<x-filament-panels::page>
    <div style="display:flex;flex-direction:column;gap:16px">
        <section style="background:#fff;border:2px solid #0B5D6B;border-radius:12px;padding:18px">
            <form wire:submit="scan" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center">
                <label for="inventory-scan" style="flex:1 1 100%;font-size:14px;font-weight:600">Scanner l'étiquette du bien ou saisir son numéro</label>
                <input id="inventory-scan" type="text" wire:model="code" autocomplete="off" autofocus placeholder="PAT-…"
                       x-data x-init="$el.focus()" x-on:inventory-scanned.window="$nextTick(() => $el.focus())"
                       class="pat-mono"
                       style="flex:1 1 300px;font-size:20px;font-weight:800;min-height:56px;border:1px solid #8F959F;border-radius:6px;padding:9px 12px">
                <select wire:model.live="roomId" aria-label="Local en cours de contrôle"
                        style="flex:0 1 280px;min-height:56px;border:1px solid #8F959F;border-radius:6px;padding:9px 10px">
                    <option value="">Local contrôlé : non précisé</option>
                    @foreach ($this->getRoomOptions() as $id => $name)
                        <option value="{{ $id }}">Local contrôlé : {{ $name }}</option>
                    @endforeach
                </select>
                <x-filament::button type="submit" size="lg">Enregistrer</x-filament::button>
                @if ($lastOutcome)
                    <div role="status" style="flex:1 1 100%;border-radius:8px;padding:10px 14px;font-size:14px;{{ $tones[$lastOutcome['tone']] ?? $tones['info'] }}">
                        {{ $lastOutcome['message'] }}
                    </div>
                @endif
            </form>
        </section>

        <section wire:poll.15s.visible style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px">
            @foreach ([
                ['Attendus', $counters['expected'], 'background:#fff;border:1px solid #D7DBE1'],
                ['Présents', $counters['present'], $tones['success']],
                ['Manquants', $counters['missing'], $tones['danger']],
                ['Écarts (état, affectation)', $counters['discrepancies'], $tones['warning']],
                ['Non enregistrés', $counters['unregistered'], $tones['info']],
                ['Hors périmètre', $counters['out_of_scope'], $tones['info']],
                ['Justifiés', $counters['justified'], 'background:#E9ECF0;color:#4A5560'],
                ['Reste à vérifier', $counters['pending'], 'background:#E9ECF0;color:#4A5560'],
            ] as [$label, $value, $style])
                <div style="display:flex;flex-direction:column;gap:2px;padding:12px 16px;border-radius:10px;{{ $style }}">
                    <b class="pat-mono" style="font-size:22px">{{ $value }}</b>
                    <span style="font-size:12px;font-weight:600">{{ $label }}</span>
                </div>
            @endforeach
        </section>

        <section style="background:#fff;border:1px solid #D7DBE1;border-radius:12px">
            <div style="padding:14px 16px;display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center">
                <h2 style="font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Biens attendus</h2>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    <select wire:model.live="resultFilter" aria-label="Filtrer par résultat" style="min-height:44px;border:1px solid #8F959F;border-radius:6px;padding:0 10px">
                        <option value="">Tous les résultats</option>
                        @foreach (\App\Enums\InventoryLineResult::cases() as $case)
                            @if ($case !== \App\Enums\InventoryLineResult::Unregistered)
                                <option value="{{ $case->value }}">{{ $case->getLabel() }}</option>
                            @endif
                        @endforeach
                    </select>
                    <input type="search" wire:model.live.debounce.400ms="search" aria-label="Filtrer la liste" placeholder="Filtrer…"
                           style="max-width:260px;min-height:44px;border:1px solid #8F959F;border-radius:6px;padding:0 10px">
                </div>
            </div>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="text-align:left;font-size:11px;color:#686868;text-transform:uppercase;letter-spacing:.06em">
                            <th style="padding:10px 12px;border-bottom:2px solid #B9BFC8">N°</th>
                            <th style="padding:10px 12px;border-bottom:2px solid #B9BFC8">Désignation</th>
                            <th style="padding:10px 12px;border-bottom:2px solid #B9BFC8">Local enregistré</th>
                            <th style="padding:10px 12px;border-bottom:2px solid #B9BFC8">État enregistré</th>
                            <th style="padding:10px 12px;border-bottom:2px solid #B9BFC8">État constaté</th>
                            <th style="padding:10px 12px;border-bottom:2px solid #B9BFC8">Résultat</th>
                            <th style="padding:10px 12px;border-bottom:2px solid #B9BFC8;min-width:380px">Pointage</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $line)
                            @php
                                $outside = $line->result === \App\Enums\InventoryLineResult::OutOfScope;
                                $isPresent = in_array($line->result, [\App\Enums\InventoryLineResult::Present, \App\Enums\InventoryLineResult::ConditionMismatch], true);
                            @endphp
                            <tr wire:key="line-{{ $line->id }}-{{ $line->lock_version }}" style="border-bottom:1px solid #E9ECF0">
                                <td style="padding:9px 12px">
                                    @if ($url = $this->assetUrl($line))
                                        <a href="{{ $url }}" class="pat-mono" style="font-weight:600;font-size:12px;color:#0B5D6B">{{ $line->asset->number }}</a>
                                    @endif
                                </td>
                                <td style="padding:9px 12px">{{ $line->asset?->label() }}</td>
                                <td style="padding:9px 12px">{{ $line->expectedRoom->name ?? '—' }}</td>
                                <td style="padding:9px 12px">{{ $line->expectedCondition->label ?? '—' }}</td>
                                <td style="padding:9px 12px">
                                    <select aria-label="État constaté {{ $line->asset?->number }}"
                                            wire:change="setCondition({{ $line->id }}, $event.target.value, {{ $line->lock_version }})"
                                            style="min-width:140px;min-height:40px;border:1px solid #8F959F;border-radius:6px;padding:0 8px">
                                        <option value="">·</option>
                                        @foreach ($conditions as $id => $label)
                                            <option value="{{ $id }}" @selected($line->found_condition_id === $id)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="padding:9px 12px">
                                    <x-filament::badge :color="$line->result->getColor()" class="w-fit">
                                        {{ $line->result === \App\Enums\InventoryLineResult::Justified ? 'Justifié · '.($line->expected_status?->getLabel() ?? '') : $line->result->getLabel() }}
                                    </x-filament::badge>
                                    @if ($line->checker)
                                        <div style="font-size:11px;color:#686868;margin-top:2px">{{ $line->checker->fullName() }} · {{ $line->checked_at?->format('H:i') }}</div>
                                    @endif
                                    @if ($line->result === \App\Enums\InventoryLineResult::OtherAssignment)
                                        <div style="font-size:11px;color:#8A5A00">Trouvé : {{ collect([$line->foundDepartment?->name, $line->foundRoom?->name])->filter()->join(' · ') }}</div>
                                    @endif
                                </td>
                                <td style="padding:9px 12px">
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                        @unless ($outside)
                                            <button type="button" wire:click="mark({{ $line->id }}, 'present', {{ $line->lock_version }})"
                                                    aria-label="Marquer {{ $line->asset?->number }} présent"
                                                    style="{{ $btn }}{{ $isPresent ? 'background:#1E6E43;color:#fff;border:1px solid #1E6E43' : 'background:#fff;color:#1E6E43;border:1px solid #9CCBB0' }}">Présent</button>
                                            <button type="button" wire:click="mark({{ $line->id }}, 'missing', {{ $line->lock_version }})"
                                                    aria-label="Marquer {{ $line->asset?->number }} manquant"
                                                    style="{{ $btn }}{{ $line->result === \App\Enums\InventoryLineResult::Missing ? 'background:#A8261B;color:#fff;border:1px solid #A8261B' : 'background:#fff;color:#A8261B;border:1px solid #E3B4AF' }}">Manquant</button>
                                            {{ ($this->otherAssignmentAction)(['line' => $line->id, 'version' => $line->lock_version]) }}
                                        @else
                                            <span style="font-size:12px;color:#2F4A9E">Hors de la liste attendue</span>
                                        @endunless
                                        {{ ($this->noteAction)(['line' => $line->id, 'version' => $line->lock_version]) }}
                                    </div>
                                    @if ($line->notes)
                                        <div style="font-size:12px;color:#686868;margin-top:4px">{{ $line->notes }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" style="padding:16px;color:#686868">Aucun bien ne correspond au filtre.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div style="padding:12px 16px">
                <x-filament::pagination :paginator="$lines" />
            </div>
        </section>

        <section style="background:#fff;border:1px solid #D7DBE1;border-radius:12px;padding:18px;display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:center">
                <h2 style="font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Biens trouvés mais non enregistrés</h2>
                {{ $this->addUnregisteredAction }}
            </div>
            @forelse ($this->getUnregistered() as $line)
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:12px;border:1px dashed #B8C0C8;border-radius:8px;font-size:14px">
                    <x-filament::badge color="info">Sans étiquette</x-filament::badge>
                    <span style="flex:1 1 260px">{{ $line->description }}{{ $line->foundRoom ? ' · '.$line->foundRoom->name : '' }}{{ $line->foundCondition ? ' · '.mb_strtolower($line->foundCondition->label) : '' }}</span>
                    <span style="font-size:12px;color:#686868">{{ $line->checker?->fullName() }} · {{ $line->checked_at?->format('H:i') }}</span>
                </div>
            @empty
                <p style="font-size:14px;color:#686868;margin:0">Aucun pour l'instant. La fiche d'un bien trouvé se crée depuis le rapport, après la clôture.</p>
            @endforelse
        </section>
    </div>

</x-filament-panels::page>
