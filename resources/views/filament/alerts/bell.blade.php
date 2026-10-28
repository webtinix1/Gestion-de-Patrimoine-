@php
    $count = \App\Filament\Pages\Alerts::unreadCount();
@endphp
<a href="{{ \App\Filament\Pages\Alerts::getUrl() }}" aria-label="Alertes{{ $count > 0 ? ' : '.$count.' non lue(s)' : '' }}" title="Alertes"
    style="position: relative; display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; color: #2E2E2E">
    <x-filament::icon icon="heroicon-o-bell" style="width: 24px; height: 24px" />
    @if ($count > 0)
        <span data-alert-count style="position: absolute; top: 4px; right: 2px; min-width: 18px; padding: 0 4px; background: #A8261B; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center">{{ $count > 99 ? '99+' : $count }}</span>
    @endif
</a>
