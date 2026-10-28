{{-- E45 : emplacements de signature. Chaque signataire : un libellé, ou [libellé, nom]. Texte échappé, apostrophes conservées. --}}
<table class="signatures">
    <tr>
        @foreach ($signatures as $signature)
            @php [$label, $name] = array_pad((array) $signature, 2, null); @endphp
            <td>{!! htmlspecialchars((string) $label, ENT_NOQUOTES) !!}@if ($name)<br><span class="muted">{{ $name }}</span>@endif<div class="box"></div></td>
        @endforeach
    </tr>
</table>
