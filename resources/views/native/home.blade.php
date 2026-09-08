<native:column ref="home-screen" class="w-full h-full safe-area bg-theme-background">
    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full p-6 gap-6">
            <native:row class="w-full h-[6] gap-1" a11y-hidden="true">
                @foreach (['pink', 'orange', 'sun', 'mint', 'sky', 'violet'] as $color)
                    <native:rect class="flex-1 h-[6] bg-theme-{{ $color }}" />
                @endforeach
            </native:row>
            <native:row class="w-full items-center justify-between gap-4">
                <native:column class="flex-1 gap-1">
                    <native:text class="text-sm font-mono text-theme-mint">LEARN. STRUM. REPEAT.</native:text>
                    <native:text font="pixel" class="text-3xl text-theme-on-background">UKE OR</native:text>
                    <native:text font="pixel" class="text-3xl text-theme-pink">PUKE</native:text>
                </native:column>
                <native:pixel-pal key="home-pal" />
            </native:row>
            <native:column class="w-full gap-2">
                <native:text class="text-2xl font-semibold text-theme-on-background">Your first little jam</native:text>
                <native:text class="text-base text-theme-on-surface-variant">Two chords. Eight strums. A whole lot of rainbow.</native:text>
            </native:column>
            <native:column class="w-full p-4 gap-4 bg-theme-surface rounded-lg border border-theme-outline">
                <native:row class="w-full items-center justify-between">
                    <native:text class="text-sm font-mono text-theme-sun">LEVEL 01 / FIRST STEPS</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">Beginner</native:text>
                </native:row>
                <native:row class="w-full items-center gap-3">
                    <native:text font="headline" class="text-3xl text-theme-pink">C</native:text>
                    <native:text class="text-xl text-theme-on-surface-variant">→</native:text>
                    <native:text font="headline" class="text-3xl text-theme-mint">Am</native:text>
                    <native:column class="flex-1 gap-1">
                        <native:text class="text-base font-semibold text-theme-on-surface">The rainbow switch</native:text>
                        <native:text class="text-base text-theme-on-surface-variant">One down-strum every 4 beats.</native:text>
                    </native:column>
                </native:row>
                <native:button label="{{ $showLesson ? 'Hide chord guide' : 'Meet your two chords' }}" variant="secondary" @tap="toggleLesson" />
                @if ($showLesson)
                    <native:row class="w-full gap-3">
                        <native:button label="C major" variant="{{ $chord === 'C' ? 'secondary' : 'ghost' }}" @tap="chooseChord('C')" class="flex-1" />
                        <native:button label="A minor" variant="{{ $chord === 'Am' ? 'secondary' : 'ghost' }}" @tap="chooseChord('Am')" class="flex-1" />
                    </native:row>
                    <native:row class="w-full gap-4 items-center">
                        <native:chord-chart :chord="$chord" key="lesson-chart" />
                        <native:column class="flex-1 gap-2">
                            <native:text class="text-base text-theme-on-surface">{{ \App\Support\BeginnerLesson::chord($chord)['instruction'] }}</native:text>
                            <native:text class="text-base text-theme-on-surface-variant">Keep the other strings open. Strum all four.</native:text>
                        </native:column>
                    </native:row>
                    <native:text class="text-base text-theme-on-surface-variant">Hold the uke with its neck to your left. Charts face you, headstock up. Circles mean open strings; dots show your fingers. 2 = middle, 3 = ring.</native:text>
                    <native:text class="text-base text-theme-on-surface-variant">Use standard G–C–E–A tuning. Place your finger just behind the fret, not on the metal.</native:text>
                @endif
            </native:column>
            <native:column class="w-full gap-3">
                <native:text class="text-base font-semibold text-theme-on-background">Find your pace</native:text>
                <native:row class="w-full gap-2">
                    @foreach ($tempos as $name => $bpm)
                        <native:pressable ref="speed-{{ $name }}" @tap="chooseSpeed('{{ $name }}')" a11y-label="{{ ucfirst($name) }}, {{ $bpm }} beats per minute{{ $speed === $name ? ', selected' : '' }}" class="flex-1 h-[64] items-center justify-center gap-1 rounded-lg border {{ $speed === $name ? 'bg-theme-mint/15 border-theme-mint' : 'bg-theme-surface border-theme-outline' }}">
                            <native:text class="text-base font-semibold {{ $speed === $name ? 'text-theme-mint' : 'text-theme-on-surface' }}">{{ ucfirst($name) }}</native:text>
                            <native:text class="text-sm font-mono text-theme-on-surface-variant">{{ $bpm }} BPM</native:text>
                        </native:pressable>
                    @endforeach
                </native:row>
            </native:column>
            <native:column class="w-full gap-2">
                <native:button label="Play with your ukulele" @tap="microphone" size="lg" class="w-full" />
                <native:button label="Microphone soundcheck" @tap="soundcheck" variant="secondary" class="w-full" />
                <native:button label="Let's jam" @tap="start" size="lg" class="w-full" />
                <native:button label="Watch a demo" @tap="demo" variant="secondary" size="lg" class="w-full" />
                <native:text class="text-base text-center text-theme-on-surface-variant">Play C and Am with microphone feedback, or choose Let's jam to practice screen taps. Visual beats only.</native:text>
            </native:column>
            <native:column class="w-full gap-3">
                <native:row class="w-full items-center justify-between">
                    <native:text class="text-base font-semibold text-theme-on-background">High scores</native:text>
                    <native:text class="text-sm font-mono text-theme-on-surface-variant">TOP {{ $boardSize }}</native:text>
                </native:row>
                @if ($best)
                    <native:row class="w-full items-center gap-4 p-4 bg-theme-surface rounded-lg border border-theme-outline" a11y-label="Best round, {{ $best->points }} points, {{ $best->playedWithUkulele() ? 'ukulele' : 'taps' }}">
                        <native:column class="items-center gap-1">
                            <native:text class="text-sm font-mono text-theme-on-surface-variant">BEST</native:text>
                            <native:text font="headline" class="text-3xl text-theme-sun">{{ str_pad((string) $best->points, 3, '0', STR_PAD_LEFT) }}</native:text>
                        </native:column>
                        <native:column class="flex-1 gap-1">
                            <native:text class="text-base text-theme-on-surface">{{ $best->playedWithUkulele() ? 'Ukulele' : 'Taps' }} · {{ $best->hits }} of {{ count(\App\Support\BeginnerLesson::CHORDS) }} on time</native:text>
                            <native:text class="text-sm font-mono text-theme-on-surface-variant">{{ strtoupper($best->speed) }} / {{ $best->bpm }} BPM</native:text>
                        </native:column>
                    </native:row>
                @else
                    <native:column class="w-full p-4 gap-1 bg-theme-surface rounded-lg border border-theme-outline">
                        <native:text class="text-base text-theme-on-surface">The board is empty.</native:text>
                        <native:text class="text-base text-theme-on-surface-variant">Play one round and every place on it is yours.</native:text>
                    </native:column>
                @endif
                <native:button label="See the high scores" @tap="scores" variant="secondary" class="w-full" />
            </native:column>
            <native:text class="text-sm text-center font-mono text-theme-on-surface-variant">ORIGINAL EXERCISE / NO SONG REQUIRED</native:text>
        </native:column>
    </native:scroll-view>
</native:column>
