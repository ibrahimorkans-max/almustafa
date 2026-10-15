<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Connection now</x-slot>
        <div wire:poll.5s>
            @php $scales = $this->scales(); @endphp
            @if ($scales === [])
                <p class="text-sm text-gray-500">No weighbridge is set up yet: start with step 4.</p>
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
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">How it connects</x-slot>
        <p class="text-sm">Load cells → <strong>indicator</strong> (the box showing the weight) → RS-232 cable or network → <strong>weighbridge PC</strong> running the gate agent → internet → this ERP. The agent sends the reading about once a second and keeps working offline.</p>
    </x-filament::section>

    @php
        $steps = [
        ['What you need', 'An indicator with an RS-232 (9-pin) or Ethernet output; a Windows 10/11 PC at the weighbridge, always on and on the internet (a UPS is advised); the cable (straight or null-modem, as the indicator manual says, up to about 15 m; longer needs an RS-232-to-Ethernet converter); a USB-to-RS232 adapter (FTDI chip) if the PC has no COM port; a valid stamping certificate.'],
        ['Set up the indicator', 'In its settings (or ask the scale engineer): serial output CONTINUOUS (not "print on key"), baud rate 9600 (or note it), 8 data bits, no parity, 1 stop bit, unit kg. Note the division (smallest step, e.g. 10 kg).'],
        ['Check the data reaches the PC', 'Plug the cable in. Device Manager → Ports (COM & LPT): note the port, e.g. COM3. Open PuTTY → Serial, that port and baud rate: lines like "ST,GS,+0025480kg" must scroll by. Nothing: wrong port, cable type or output mode. Strange characters: wrong baud rate. Close PuTTY afterwards.'],
        ['Prepare the ERP', 'Weighbridges → New (code, name, capacity, stamping certificate) and copy its id. Users → a login only for the PC with the role Weighbridge / Gate Operator (e.g. wb.agent). Devices → Register gate agent for that login: copy the device_uid and the signing secret (shown only once).'],
        ['Install the gate agent on the PC', 'Install Python 3.12, copy the agent folder to C:\\RiceMillAgent, then in PowerShell there: py -3.12 -m venv .venv · .venv\\Scripts\\pip install -e .[serial] · .venv\\Scripts\\ricemill-agent new-store-key · copy agent.example.toml agent.toml.'],
        ['Fill in agent.toml', 'server url = '.$this->serverUrl().' · username, device_uid, the weighbridge id · [indicator] type = "serial", port = "COM3", baudrate = 9600 (network indicator: type = "tcp", host, port) · resolution_kg = the division. Run ricemill-agent protect for the password, signing secret and store key and paste the dpapi:… values.'],
        ['Test', 'ricemill-agent --config agent.toml sync must finish without an error. Then ricemill-agent --config agent.toml live and open Live weight: the number must follow the indicator within a second and show Stable when it settles. Check with a known weight.'],
        ['Keep it running', 'Task Scheduler: at startup (restart on failure) … ricemill-agent.exe --config C:\\RiceMillAgent\\agent.toml live; every minute … sync. Both as the PC\'s service account, whether or not someone is signed in.'],
        ['Record and check weights', 'Live weight: choose the vehicle on the platform, press Record gross / Record tare when it shows Stable. Weight checks: the Super Admin (or the Owner) marks each weight OK or queries it.'],
    ];
    @endphp
    <x-filament::section>
        <x-slot name="heading">Steps</x-slot>
        <ol class="list-decimal space-y-3 pl-5 text-sm">
            @foreach ($steps as [$title, $body])
                <li><strong>{{ $title }}.</strong> {{ $body }}</li>
            @endforeach
        </ol>
        <p class="mt-4 text-sm">
            Shortcuts: <a class="text-primary-600 underline" href="{{ $this->scalesUrl() }}">Weighbridges</a> ·
            <a class="text-primary-600 underline" href="{{ $this->usersUrl() }}">Users</a> ·
            <a class="text-primary-600 underline" href="{{ $this->devicesUrl() }}">Devices</a> ·
            <a class="text-primary-600 underline" href="{{ $this->liveUrl() }}">Live weight</a>
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">If something is wrong</x-slot>
        <ul class="list-disc space-y-1 pl-5 text-sm">
            <li><strong>No signal</strong>: is the agent running (live)? Is the PC online, the cable in, the indicator on?</li>
            <li><strong>Wrong numbers</strong> (×1000, missing digits): the indicator's frame differs; set frame_pattern in agent.toml (the scale engineer knows the format).</li>
            <li><strong>Always Moving</strong>: resolution_kg smaller than the indicator's division, or a wobbling platform.</li>
            <li><strong>"Outside the weighbridge range"</strong>: the capacity under Weighbridges is wrong, or the frame is misread.</li>
            <li><strong>"This device is new to your account"</strong> for the agent: approve it under Approvals, or register it again under Devices.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
