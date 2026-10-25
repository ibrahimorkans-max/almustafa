<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Connection now</x-slot>
        <div wire:poll.5s>
            @php $scales = $this->scales(); @endphp
            @if ($scales === [])
                <p class="text-sm text-gray-500">No weighbridge is set up yet: follow the steps below.</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($scales as $s)
                        <li class="flex items-center gap-2">
                            <x-filament::badge :color="$s['live'] ? 'success' : 'danger'">{{ $s['live'] ? 'Connected' : 'No signal' }}</x-filament::badge>
                            <span><strong>{{ $s['code'] }}</strong> · {{ $s['name'] }}</span>
                            <span class="text-gray-500">
                                @if ($s['kg'] !== null)
                                    last reading {{ number_format($s['kg'], 0) }} kg, {{ $s['age_seconds'] }} s ago
                                @else
                                    no reading received yet
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-3 text-xs text-gray-500">Load cells → <strong>indicator</strong> → RS-232 cable → <strong>weighbridge PC</strong> (gate agent, camera) → internet → this ERP.</p>
        </div>
    </x-filament::section>

    @php $cd = $this->connectionDetails(); @endphp
    <x-filament::section collapsible>
        <x-slot name="heading">Details to copy</x-slot>
        <x-slot name="description">What the weighbridge PC asks for. The PC's password and the signing secret are not shown here: the password is never stored readable, and the secret is shown once, when the agent is registered (register it again under Devices if it was lost).</x-slot>
        <div class="space-y-4 text-sm">
            <div>
                <div class="mb-1 text-xs font-medium text-gray-500">ERP address</div>
                <div class="flex items-center gap-2" x-data="{ copied: false }">
                    <code class="flex-1 overflow-x-auto rounded-lg bg-gray-950 px-3 py-2 font-mono text-xs text-gray-100">{{ $cd['server'] }}</code>
                    <x-filament::button size="xs" color="gray" x-on:click="navigator.clipboard.writeText(@js($cd['server'])); copied = true; setTimeout(() => copied = false, 1500)"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></x-filament::button>
                </div>
            </div>

            <div>
                <div class="mb-1 text-xs font-medium text-gray-500">Gate agents (registered PCs)</div>
                @forelse ($cd['agents'] as $ag)
                    <div class="mb-2 rounded-lg border border-gray-200 p-2 dark:border-white/10">
                        <div class="mb-1 text-xs text-gray-500">{{ $ag['name'] }} · login <strong>{{ $ag['username'] }}</strong> · {{ $ag['status'] }} · last seen {{ $ag['last_seen'] }}</div>
                        @foreach (['device_uid' => $ag['device_uid'], 'username' => $ag['username']] as $label => $value)
                            <div class="mb-1 flex items-center gap-2" x-data="{ copied: false }">
                                <span class="w-24 shrink-0 text-xs text-gray-500">{{ $label }}</span>
                                <code class="flex-1 overflow-x-auto rounded bg-gray-950 px-2 py-1 font-mono text-xs text-gray-100">{{ $value }}</code>
                                <x-filament::button size="xs" color="gray" x-on:click="navigator.clipboard.writeText(@js($value)); copied = true; setTimeout(() => copied = false, 1500)"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></x-filament::button>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="text-gray-500">No gate agent registered yet: Security → Devices → Register gate agent.</p>
                @endforelse
            </div>

            <div>
                <div class="mb-1 text-xs font-medium text-gray-500">Weighbridges</div>
                @forelse ($cd['scales'] as $sc)
                    <div class="mb-3 rounded-lg border border-gray-200 p-2 dark:border-white/10">
                        <div class="mb-1 text-xs text-gray-500"><strong>{{ $sc['code'] }}</strong> · {{ $sc['name'] }} · step {{ $sc['resolution_kg'] }} kg · {{ $sc['status'] }}</div>
                        <div class="mb-2 flex items-center gap-2" x-data="{ copied: false }">
                            <span class="w-24 shrink-0 text-xs text-gray-500">weighbridge id</span>
                            <code class="flex-1 overflow-x-auto rounded bg-gray-950 px-2 py-1 font-mono text-xs text-gray-100">{{ $sc['id'] }}</code>
                            <x-filament::button size="xs" color="gray" x-on:click="navigator.clipboard.writeText(@js($sc['id'])); copied = true; setTimeout(() => copied = false, 1500)"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></x-filament::button>
                        </div>
                        <div x-data="{ copied: false }">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="text-xs text-gray-500">agent.toml for this weighbridge (fill in the three empty secrets on the PC)</span>
                                <x-filament::button size="xs" color="gray" x-on:click="navigator.clipboard.writeText(@js($sc['toml'])); copied = true; setTimeout(() => copied = false, 1500)"><span x-text="copied ? 'Copied' : 'Copy agent.toml'">Copy agent.toml</span></x-filament::button>
                            </div>
                            <pre class="overflow-x-auto rounded-lg bg-gray-950 px-3 py-2 font-mono text-xs text-gray-100">{{ $sc['toml'] }}</pre>
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500">No weighbridge added yet: Weighbridges → New.</p>
                @endforelse
            </div>
        </div>
    </x-filament::section>

    @php
        $steps = $this->steps();
        $current = $steps[$step - 1];
        $doneCount = collect($steps)->where('done', true)->count();
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section class="lg:col-span-1">
            <x-slot name="heading">Steps · {{ $doneCount }} of {{ count($steps) }} done</x-slot>
            <ol class="space-y-1">
                @foreach ($steps as $i => $s)
                    <li>
                        <button type="button" wire:click="go({{ $i + 1 }})"
                            @class([
                                'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm',
                                'bg-primary-50 font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $i + 1 === $step,
                                'hover:bg-gray-50 dark:hover:bg-white/5' => $i + 1 !== $step,
                            ])>
                            <span @class([
                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                'bg-success-600 text-white' => $s['done'],
                                'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300' => ! $s['done'],
                            ])>{{ $s['done'] ? '✓' : $i + 1 }}</span>
                            <span class="flex-1">{{ $s['title'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">Step {{ $step }} of {{ count($steps) }}: {{ $current['title'] }}</x-slot>
            <x-slot name="description">{{ $current['device'] }}</x-slot>

            <div class="space-y-4 text-sm">
                <p>{{ $current['intro'] }}</p>

                <ol class="list-decimal space-y-2 pl-5">
                    @foreach ($current['do'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ol>

                @if ($current['commands'] !== [])
                    <div>
                        <div class="mb-1 text-xs font-medium text-gray-500">Type on the weighbridge PC (PowerShell, in the agent folder):</div>
                        @foreach ($current['commands'] as $cmd)
                            <div class="mb-2 flex items-center gap-2" x-data="{ copied: false }">
                                <code class="flex-1 overflow-x-auto rounded-lg bg-gray-950 px-3 py-2 font-mono text-xs text-gray-100">{{ $cmd }}</code>
                                <x-filament::button size="xs" color="gray" x-on:click="navigator.clipboard.writeText(@js($cmd)); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                </x-filament::button>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($current['links'] !== [])
                    <div class="flex flex-wrap gap-2">
                        @foreach ($current['links'] as $link)
                            <x-filament::button tag="a" :href="$link['url']" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">{{ $link['label'] }}</x-filament::button>
                        @endforeach
                    </div>
                @endif

                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    @if ($current['auto'] === true)
                        <x-filament::badge color="success" icon="heroicon-o-check-circle">Done — the system can see this step is finished</x-filament::badge>
                    @elseif ($current['auto'] === false)
                        <x-filament::badge color="warning" icon="heroicon-o-clock">Not yet — this is ticked by itself once it is done</x-filament::badge>
                    @else
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:click="toggle({{ $step }})" @checked($current['done']) class="rounded border-gray-300 text-primary-600">
                            <span>I have done this step</span>
                        </label>
                    @endif
                </div>

                <div class="flex justify-between">
                    <x-filament::button color="gray" wire:click="back" :disabled="$step === 1" icon="heroicon-o-arrow-left">Back</x-filament::button>
                    <x-filament::button wire:click="next" :disabled="$step === count($steps)" icon="heroicon-o-arrow-right" icon-position="after">Next step</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    </div>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">If something is wrong</x-slot>
        <ul class="list-disc space-y-1 pl-5 text-sm">
            <li><strong>First</strong>: on the weighbridge PC run <code>.venv\Scriptsicemill-agent --config agent.toml check</code>. It tests every step in order and says in plain words what is wrong and what to do.</li>
            <li><strong>No signal</strong>: is the agent running (live)? Is the PC online, the cable in, the indicator on?</li>
            <li><strong>detect finds nothing</strong>: continuous output is off, wrong cable (try null-modem), or another program uses the COM port.</li>
            <li><strong>Wrong numbers</strong> (×1000, missing digits): run detect again with a weight on; for unusual indicators set frame_pattern in agent.toml.</li>
            <li><strong>Always Moving</strong>: resolution_kg in agent.toml is smaller than the indicator's division, or the platform wobbles.</li>
            <li><strong>No camera photo</strong>: run camera-test; check the camera name / RTSP address and that ffmpeg is installed.</li>
            <li><strong>"This device is new to your account"</strong> for the agent: approve it under Approvals, or register it again under Devices.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
