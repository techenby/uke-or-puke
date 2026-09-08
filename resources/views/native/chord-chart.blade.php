<native:column class="w-[120] h-[142]" a11y-label="{{ $chord }} chord. {{ $shape['instruction'] }} Other strings open.">
    @foreach (['G', 'C', 'E', 'A'] as $string => $label)
        <native:text class="absolute top-[0] left-[{{ 14 + $string * 27 }}] w-[24] text-center text-sm text-theme-on-surface-variant">{{ $label }}</native:text>
        @if ($shape['frets'][$string] === 0)
            <native:text class="absolute top-[20] left-[{{ 14 + $string * 27 }}] w-[24] text-center text-sm text-theme-on-surface">○</native:text>
        @endif
        <native:rect class="absolute top-[43] left-[{{ 25 + $string * 27 }}] w-[2] h-[90] bg-theme-on-surface-variant/50" />
    @endforeach
    @foreach (range(0, 3) as $fret)
        <native:rect class="absolute top-[{{ 43 + $fret * 30 }}] left-[25] w-[83] h-[{{ $fret === 0 ? 4 : 1 }}] bg-theme-on-surface-variant/70" />
        @if ($fret > 0)
            <native:text class="absolute top-[{{ 19 + $fret * 30 }}] left-[0] w-[16] text-sm text-theme-on-surface-variant">{{ $fret }}</native:text>
        @endif
    @endforeach
    @foreach ($shape['frets'] as $string => $fret)
        @if ($fret > 0)
            <native:column class="absolute top-[{{ 17 + $fret * 30 }}] left-[{{ 14 + $string * 27 }}] w-[24] h-[24] items-center justify-center bg-theme-{{ $shape['color'] }} rounded-full">
                <native:text class="text-sm font-semibold text-theme-ink">{{ $shape['finger'] }}</native:text>
            </native:column>
        @endif
    @endforeach
</native:column>
