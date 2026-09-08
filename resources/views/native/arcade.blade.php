<native:column class="w-full h-full safe-area bg-theme-background">
    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full p-4 gap-3">
            <native:row class="w-full items-center justify-between gap-3">
                <native:column class="flex-1 gap-1">
                    <native:text class="text-sm font-mono text-theme-mint">{{ $demo ? 'DEMO / AUTO PLAY' : 'ARCADE / TAP TO PLAY' }}</native:text>
                    <native:text class="text-xl font-semibold text-theme-on-background">The rainbow switch</native:text>
                </native:column>
                @if ($status === 'playing')
                    <native:button label="Pause" variant="secondary" @tap="pause" />
                @else
                    <native:button label="Home" variant="ghost" @tap="home" />
                @endif
            </native:row>

            @if ($status === 'finished')
                <native:column class="w-full items-center gap-4 py-6">
                    <native:pixel-pal key="result-pal" />
                    <native:text font="headline" class="text-3xl text-center text-theme-sun">{{ $demo ? 'THAT’S THE FLOW!' : ($hits >= 6 ? 'UKE-TASTIC!' : 'KEEP ON STRUMMIN’') }}</native:text>
                    <native:text class="text-base text-center text-theme-on-surface-variant">{{ $demo ? 'C, then Am. A little switch, a little rainbow.' : 'Every round is another step. Ready for one more?' }}</native:text>
                </native:column>
                @if (! $demo)
                    <native:column class="w-full items-center p-5 gap-2 bg-theme-surface rounded-lg">
                        <native:text class="text-sm font-mono text-theme-on-surface-variant">TAP TIMING SCORE</native:text>
                        <native:text font="headline" class="text-5xl text-theme-mint">{{ $score }}</native:text>
                        <native:text class="text-base text-theme-on-surface-variant">of 800 possible points</native:text>
                    </native:column>
                    <native:row class="w-full gap-3 justify-between">
                        <native:column class="flex-1 items-center gap-1">
                            <native:text class="text-2xl font-semibold text-theme-pink">{{ $accuracy }}%</native:text>
                            <native:text class="text-base text-theme-on-surface-variant">On time</native:text>
                        </native:column>
                        <native:column class="flex-1 items-center gap-1">
                            <native:text class="text-2xl font-semibold text-theme-sun">{{ $bestStreak }}</native:text>
                            <native:text class="text-base text-theme-on-surface-variant">Best streak</native:text>
                        </native:column>
                        <native:column class="flex-1 items-center gap-1">
                            <native:text class="text-2xl font-semibold text-theme-sky">{{ $perfect }}</native:text>
                            <native:text class="text-base text-theme-on-surface-variant">Perfect</native:text>
                        </native:column>
                    </native:row>
                    <native:text class="text-base text-center text-theme-on-surface-variant">{{ $hits }} of 8 cues hit. {{ 8 - $hits }} splats. These results measure screen taps, not your ukulele.</native:text>
                @else
                    <native:text class="text-base text-center text-theme-on-surface-variant">Demo complete. No score was recorded. When you’re ready, try tapping along from Home.</native:text>
                @endif
                <native:button label="{{ $demo ? 'Watch again' : 'Play again' }}" size="lg" @tap="start" class="w-full" />
                <native:button label="Change pace" variant="secondary" @tap="home" />
            @else
                <native:row class="w-full items-center justify-between">
                    <native:text class="text-base font-mono text-theme-on-surface">{{ $demo ? 'AUTO' : str_pad((string) $score, 3, '0', STR_PAD_LEFT) }} / {{ $bpm }} BPM</native:text>
                    <native:text class="text-base font-mono text-theme-sun">{{ $demo ? '8 STRUMS' : $streak.' STREAK' }}</native:text>
                    <native:text class="text-base font-mono text-theme-on-surface-variant">{{ $seconds }}s</native:text>
                </native:row>
                <native:column class="w-full h-[206] overflow-hidden bg-theme-surface rounded-lg border border-theme-outline" a11y-label="Chord cues move down toward the strum line.">
                    <native:row class="absolute top-[0] left-[16] right-[16] h-[206] justify-around" a11y-hidden="true">
                        @foreach (['pink', 'sun', 'mint', 'sky'] as $color)
                            <native:rect class="w-[2] h-[206] bg-theme-{{ $color }}/20" />
                        @endforeach
                    </native:row>
                    @foreach ([40, 80, 120] as $line)
                        <native:rect class="absolute top-[{{ $line }}] left-[0] right-[0] h-[1] bg-theme-outline/50" />
                    @endforeach
                    <native:rect class="absolute top-[159] left-[0] right-[0] h-[3] bg-theme-sun" />
                    <native:text class="absolute top-[177] left-[0] right-[0] text-sm text-center font-mono text-theme-sun">STRUM LINE</native:text>
                    @if ($status === 'playing')
                        @foreach ($cues as $cue)
                            <native:row ref="cue-{{ $cue['index'] }}" class="absolute top-[{{ $cue['top'] }}] left-[22] right-[22] h-[40] px-4 items-center justify-between bg-theme-{{ $cue['color'] }} rounded-sm">
                                <native:text font="headline" class="text-xl text-theme-ink">{{ $cue['chord'] }}</native:text>
                                <native:text class="text-base font-semibold text-theme-ink">↓ All four strings</native:text>
                            </native:row>
                        @endforeach
                    @else
                        <native:column class="absolute top-[22] left-[16] right-[16] items-center gap-2">
                            <native:pixel-pal key="ready-pal" />
                            <native:text class="text-xl font-semibold text-theme-on-surface">{{ $status === 'paused' ? 'Take a little breather' : 'Let’s catch a rainbow' }}</native:text>
                        </native:column>
                    @endif
                </native:column>
                <native:row class="w-full items-center gap-3">
                    <native:text class="text-sm font-mono text-theme-on-surface-variant">{{ $countIn ? 'COUNT IN' : 'BEAT' }}</native:text>
                    @foreach (range(1, 4) as $number)
                        <native:column class="flex-1 h-[30] items-center justify-center rounded-sm {{ $status === 'playing' && $beat === $number ? 'bg-theme-sun' : 'bg-theme-surface' }}">
                            <native:text class="text-base font-mono {{ $status === 'playing' && $beat === $number ? 'text-theme-ink' : 'text-theme-on-surface-variant' }}">{{ $number }}</native:text>
                        </native:column>
                    @endforeach
                </native:row>
                <native:row class="w-full items-center gap-4 bg-theme-surface p-3 rounded-lg">
                    <native:chord-chart :chord="$chord" key="playing-chart" />
                    <native:column class="flex-1 gap-2">
                        <native:row class="w-full items-center gap-2">
                            <native:text font="headline" class="text-2xl text-theme-{{ $shape['color'] }}">{{ $chord }}</native:text>
                            <native:text class="text-sm font-mono text-theme-on-surface-variant">GET READY</native:text>
                        </native:row>
                        <native:text class="text-base text-theme-on-surface">{{ $shape['instruction'] }}</native:text>
                        <native:text class="text-base text-theme-on-surface-variant">{{ $next ? 'Up next: '.$next : 'Last chord. You’ve got this!' }}</native:text>
                    </native:column>
                </native:row>
                @if ($status === 'ready')
                    <native:text class="text-base text-center text-theme-on-surface-variant">{{ $demo ? 'Watch the cues and strum your uke as each card’s center crosses the line. This is an automatic, unscored demo.' : 'Tap STRUM as each card’s center crosses the yellow line. The four-count gives you time to get ready.' }}</native:text>
                    <native:button label="{{ $demo ? 'Start demo' : 'Start arcade' }}" size="lg" @tap="start" class="w-full" />
                @elseif ($status === 'paused')
                    <native:button label="Keep going" size="lg" @tap="resume" class="w-full" />
                    <native:button label="Leave round" variant="secondary" @tap="home" />
                @else
                    <native:row class="w-full items-center justify-center gap-3 h-[60]">
                        <native:pixel-pal :mood="$mood" key="feedback-pal" />
                        <native:text class="flex-1 text-lg font-semibold {{ $mood === 'miss' ? 'text-theme-pink' : 'text-theme-mint' }}">{{ $feedback }}</native:text>
                    </native:row>
                @endif
            @endif
        </native:column>
    </native:scroll-view>
    @if ($status === 'playing')
        <native:column class="w-full px-4 py-3 gap-2 bg-theme-background">
            @if ($demo)
                <native:text class="text-base text-center text-theme-mint">Auto play · Strum along on your uke.</native:text>
            @else
                <native:pressable ref="strum" @tap="strum" :press-scale="0.96" a11y-label="Strum. Tap when the chord reaches the yellow line." class="w-full h-[56] items-center justify-center bg-theme-mint rounded-lg">
                    <native:text font="pixel" class="text-base text-theme-ink">STRUM ↓</native:text>
                </native:pressable>
            @endif
            <native:text class="text-sm text-center text-theme-on-surface-variant">Visual beats · Microphone off</native:text>
        </native:column>
    @endif
</native:column>
