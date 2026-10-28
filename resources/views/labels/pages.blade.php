@php
    /** @var list<list<\App\Support\Labels\SheetSlot>> $pages */
    $screen = $mode === 'screen';
    $mm = fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.').'mm';
@endphp
@foreach ($pages as $pageIndex => $slots)
    <div
        @class(['label-page', 'label-page-screen' => $screen])
        style="width: {{ $mm($pageWidth) }}; height: {{ $mm($screen ? $pageHeight : $pageHeight - 0.3) }};@if (! $screen && ! $loop->last) page-break-after: always;@endif"
    >
        @foreach ($slots as $slot)
            @if ($slot->label !== null || $screen)
                <div
                    @class(['label-slot', 'label-slot-empty' => $slot->label === null])
                    style="left: {{ $mm($slot->xMm) }}; top: {{ $mm($slot->yMm) }}; width: {{ $mm($slot->widthMm) }}; height: {{ $mm($slot->heightMm) }};"
                >
                    @if ($slot->label !== null)
                        @php($label = $slot->label)
                        <img
                            src="{{ $label->code->uri }}"
                            alt=""
                            style="position: absolute; left: {{ $mm($label->codeX) }}; top: {{ $mm($label->codeY) }}; width: {{ $mm($label->code->widthMm) }}; height: {{ $mm($label->code->heightMm) }};"
                        >
                        @foreach ($label->lines as $line)
                            <div
                                class="label-line"
                                style="left: {{ $mm($line['x']) }}; top: {{ $mm($line['y']) }}; width: {{ $mm($line['w']) }}; height: {{ $mm($line['h']) }}; line-height: {{ $mm($line['h']) }}; font-size: {{ $line['pt'] }}pt; font-weight: {{ $line['bold'] ? 'bold' : 'normal' }}; font-family: {{ $line['mono'] ? "'DejaVu Sans Mono', 'Martian Mono', monospace" : "'DejaVu Sans', sans-serif" }};"
                            >{{ $line['text'] }}</div>
                        @endforeach
                    @elseif ($screen)
                        <span class="label-slot-number">{{ $slot->number }}</span>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
@endforeach
