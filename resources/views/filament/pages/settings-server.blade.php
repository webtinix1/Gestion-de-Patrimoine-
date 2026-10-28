<div>
    <p style="margin:0 0 12px;font-size:13px;color:#686868">Lecture seule. Ces contrôles ne modifient rien. Pour les corriger, voir <code>docs/07-deploiement-local.md</code> ou lancez <code>php artisan patrimoine:verifier</code>.</p>
    <table style="width:100%;border-collapse:collapse;font-size:14px">
        <tbody>
            @foreach ($checks as $check)
                <tr style="border-top:1px solid #D7DBE1">
                    <td style="padding:10px 12px 10px 0;font-weight:600;width:34%;vertical-align:top">{{ $check['label'] }}</td>
                    <td style="padding:10px 12px 10px 0;vertical-align:top;width:110px">
                        <x-filament::badge :color="match ($check['status']) { 'ok' => 'success', 'warn' => 'warning', default => 'danger' }" style="border-radius:0">
                            {{ match ($check['status']) { 'ok' => 'OK', 'warn' => 'À surveiller', default => 'Anomalie' } }}
                        </x-filament::badge>
                    </td>
                    <td style="padding:10px 0;vertical-align:top">{{ $check['detail'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
