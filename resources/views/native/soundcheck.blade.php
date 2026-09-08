<native:column class="w-full h-full bg-theme-background">
    <native:top-bar title="Soundcheck" />
    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full p-5 gap-4">
            <native:text font="pixel" class="text-xl text-theme-mint">HELLO, UKULELE</native:text>
            <native:text class="text-base text-theme-on-surface-variant">Standard high-G tuning: G4 · C4 · E4 · A4. Set your phone nearby in a quiet spot.</native:text>
            <native:row class="w-full gap-2">
                @foreach (array_keys(\App\NativeComponents\Soundcheck::NOTES) as $note)
                    <native:button label="{{ $note }}" @tap="chooseTarget('{{ $note }}')" variant="{{ $target === $note ? 'primary' : 'secondary' }}" class="flex-1" />
                @endforeach
            </native:row>
            <native:row class="w-full gap-2">
                <native:button label="C chord" @tap="chooseTarget('C')" variant="{{ $target === 'C' ? 'primary' : 'secondary' }}" class="flex-1" />
                <native:button label="Am chord" @tap="chooseTarget('Am')" variant="{{ $target === 'Am' ? 'primary' : 'secondary' }}" class="flex-1" />
            </native:row>
            <native:column class="w-full p-5 items-center gap-3 rounded-lg bg-theme-surface border {{ $matched ? 'border-theme-mint' : 'border-theme-outline' }}">
                <native:pixel-pal key="soundcheck-pal" />
                <native:text class="text-sm font-mono text-theme-on-surface-variant">{{ $microphoneStatus === 'listening' ? 'LISTENING FOR '.$target : 'READY FOR '.$target }}</native:text>
                <native:text font="headline" class="text-4xl {{ $matched ? 'text-theme-mint' : 'text-theme-sun' }}">{{ $heard }}</native:text>
                <native:text class="text-base text-center text-theme-on-surface">{{ $microphoneStatus === 'listening' ? $feedback : $microphoneMessage }}</native:text>
                <native:row class="w-full gap-1 h-[16]" a11y-label="Microphone level {{ $inputLevel }} of 10">
                    @foreach (range(1, 10) as $segment)
                        <native:rect class="flex-1 h-[16] {{ $segment <= $inputLevel ? 'bg-theme-mint' : 'bg-theme-outline' }}" />
                    @endforeach
                </native:row>
            </native:column>
            @if (in_array($target, ['C', 'Am'], true))
                <native:row class="w-full gap-4 items-center">
                    <native:chord-chart :chord="$target" key="soundcheck-chart" />
                    <native:text class="flex-1 text-base text-theme-on-surface">{{ \App\Support\BeginnerLesson::chord($target)['instruction'] }} Strum all four strings.</native:text>
                </native:row>
            @else
                <native:text class="text-base text-center text-theme-on-surface-variant">Pluck only the {{ $target }} string. Mute the others, and let it ring.</native:text>
            @endif
            @if (in_array($microphoneStatus, ['requesting', 'listening'], true))
                <native:button label="Stop listening" @tap="stopMicrophone" variant="secondary" size="lg" class="w-full" />
            @else
                <native:button label="Start listening" @tap="listen" size="lg" class="w-full" />
            @endif
            @if ($microphoneStatus === 'denied')
                <native:button label="Open Settings" @tap="microphoneSettings" variant="secondary" class="w-full" />
            @endif
            <native:text class="text-sm text-center text-theme-on-surface-variant">Audio stays on your phone. Nothing is recorded or uploaded. Chord feedback currently checks C and Am.</native:text>
        </native:column>
    </native:scroll-view>
</native:column>
