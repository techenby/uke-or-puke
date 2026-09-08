<native:column class="w-[72] h-[60]" a11y-hidden="true">
    @foreach ($pixels as $y => $row)
        @foreach (str_split($row) as $x => $pixel)
            @if (isset($colors[$pixel]))
                <native:rect class="absolute left-[{{ $x * 6 }}] top-[{{ $y * 6 }}] w-[6] h-[6] bg-theme-{{ $colors[$pixel] }}" />
            @endif
        @endforeach
    @endforeach
</native:column>
