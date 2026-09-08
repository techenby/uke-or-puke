<native:column class="w-full h-full bg-theme-background">
    <native:top-bar title="High scores" />
    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full p-5 gap-4">
            <native:row class="w-full h-[6] gap-1" a11y-hidden="true">
                @foreach (['pink', 'orange', 'sun', 'mint', 'sky', 'violet'] as $color)
                    <native:rect class="flex-1 h-[6] bg-theme-{{ $color }}" />
                @endforeach
            </native:row>
            <native:row class="w-full items-center justify-between gap-3">
                <native:column class="flex-1 gap-1">
                    <native:text font="pixel" class="text-xl text-theme-sun">TOP {{ $boardSize }}</native:text>
                    <native:text class="text-base text-theme-on-surface-variant">{{ $rounds === 0 ? 'Nobody has taken a place yet.' : $rounds.' '.\Illuminate\Support\Str::plural('round', $rounds).' played · '.$ukuleleRounds.' on the ukulele' }}</native:text>
                </native:column>
                <native:pixel-pal key="scores-pal" />
            </native:row>

            @if ($board->isEmpty())
                <native:column class="w-full p-5 gap-2 items-center bg-theme-surface rounded-lg border border-theme-outline">
                    <native:text font="headline" class="text-2xl text-center text-theme-on-surface">The board is empty</native:text>
                    <native:text class="text-base text-center text-theme-on-surface-variant">Play one round and every place on it is yours.</native:text>
                </native:column>
            @else
                <native:column class="w-full bg-theme-surface rounded-lg border border-theme-outline">
                    @foreach ($board as $place => $score)
                        <native:row class="w-full items-center gap-3 px-4 py-3 {{ $loop->last ? '' : 'border-b border-theme-outline' }}" a11y-label="Number {{ $place + 1 }}, {{ $score->points }} points, {{ $score->playedWithUkulele() ? 'ukulele' : 'taps' }}, {{ $score->created_at->diffForHumans() }}">
                            <native:text class="w-[24] text-base font-mono {{ $place === 0 ? 'text-theme-sun' : 'text-theme-on-surface-variant' }}">{{ str_pad((string) ($place + 1), 2, '0', STR_PAD_LEFT) }}</native:text>
                            <native:text font="headline" class="text-2xl {{ $place === 0 ? 'text-theme-sun' : 'text-theme-on-surface' }}">{{ str_pad((string) $score->points, 3, '0', STR_PAD_LEFT) }}</native:text>
                            <native:column class="flex-1 gap-1">
                                <native:text class="text-base text-theme-on-surface">{{ $score->playedWithUkulele() ? 'Ukulele' : 'Taps' }} · {{ $score->hits }} of {{ $cues }} on time · {{ $score->perfect }} perfect</native:text>
                                <native:text class="text-sm font-mono text-theme-on-surface-variant">{{ strtoupper($score->speed) }} / {{ $score->bpm }} BPM · STREAK {{ $score->best_streak }}</native:text>
                            </native:column>
                        </native:row>
                    @endforeach
                </native:column>
                <native:text class="text-base text-center text-theme-on-surface-variant">Matching a score isn’t enough — the earlier round keeps the higher place.</native:text>
            @endif

            <native:button label="{{ $board->isEmpty() ? 'Play the first round' : 'Take a place' }}" @tap="play" size="lg" class="w-full" />
            <native:text class="text-sm text-center font-mono text-theme-on-surface-variant">DEWEY HELD ALL TEN / UNTIL HE DIDN’T</native:text>
        </native:column>
    </native:scroll-view>
</native:column>
