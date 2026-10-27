@php
    $zoom = $width > 0 ? min(2.2, 480 / ($width * 3.7795)) : 1;
@endphp

<div class="flex flex-col items-center gap-3 rounded-xl bg-gray-100 p-4">
    <div style="zoom: {{ round($zoom, 3) }}; box-shadow: 0 0 0 1px #B8C0C8; background: #fff;">
        @include('labels.pages', ['pages' => $pages, 'mode' => 'screen', 'pageWidth' => $width, 'pageHeight' => $height])
    </div>
    <p class="text-center text-sm text-gray-600">
        Étiquette de {{ rtrim(rtrim(number_format($width, 1, ',', ''), '0'), ',') }} × {{ rtrim(rtrim(number_format($height, 1, ',', ''), '0'), ',') }} mm, affichée agrandie.
    </p>
</div>

@include('labels.styles')
