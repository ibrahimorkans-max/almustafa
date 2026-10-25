<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\RoleTemplates;
use App\Domain\Gate\LiveWeight;
use App\Filament\Resources\DeviceResource;
use App\Filament\Resources\Gate\ScaleResource;
use App\Filament\Resources\LoginAccountResource;
use App\Models\Device;
use App\Models\Gate\Scale;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Connecting the weighing devices, one step at a time: the indicator (PF-7E or any RS-232 indicator),
 * the cable, the ERP set-up, the gate agent on the weighbridge PC, the camera, the test and keeping it
 * running. Steps the system can see for itself (weighbridge added, PC login, agent registered and
 * online, readings arriving, photos arriving) are ticked automatically; the others are ticked by hand.
 */
class ConnectWeighbridge extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Connect a weighbridge';

    protected static ?string $title = 'Connect a weighbridge';

    protected static ?string $slug = 'connect-weighbridge';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.connect-weighbridge';

    /** The step shown (1-based). */
    public int $step = 1;

    /** @var list<int> steps ticked by hand (kept for this sign-in) */
    public array $ticked = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('master.manage');
    }

    public function mount(): void
    {
        $this->ticked = array_values(array_map('intval', (array) session('connect-weighbridge.ticked', [])));
        $this->step = $this->firstOpenStep();
    }

    /** @return list<array{scale_id: string, code: string, name: string, kg: float|null, stable: bool, live: bool, age_seconds: int|null}> */
    public function scales(): array
    {
        return app(LiveWeight::class)->all();
    }

    /**
     * Everything the weighbridge PC needs, ready to copy: the ERP address, each weighbridge's id, the
     * registered gate agents (device_uid and login) and an agent.toml per weighbridge. Secrets are not
     * shown: the PC's password is never stored readable and the signing secret is shown once, at registration.
     *
     * @return array{server: string, scales: list<array{id: string, code: string, name: string, resolution_kg: string, status: string, toml: string}>, agents: list<array{device_uid: string, name: string, username: string, status: string, last_seen: string}>}
     */
    public function connectionDetails(): array
    {
        $server = rtrim((string) config('app.url'), '/');
        $agents = Device::query()->with('loginAccount')->where('platform', 'agent')->where('status', Device::STATUS_ACTIVE)
            ->orderByDesc('last_seen_at')->get();
        $first = $agents->first();

        $scales = Scale::query()->orderBy('code')->get()->map(function (Scale $s) use ($server, $first): array {
            $resolution = rtrim(rtrim((string) $s->resolution_kg, '0'), '.');
            $toml = implode("\n", [
                '[server]',
                'url = "'.$server.'"',
                '',
                '[auth]',
                'username = "'.($first?->loginAccount->username ?? 'wb.agent').'"',
                'password = ""                  # ricemill-agent protect → paste the dpapi:… text',
                'device_uid = "'.($first->device_uid ?? 'agent-…').'"',
                'signing_secret = ""            # shown once at "Register gate agent"; protect it the same way',
                '',
                '[store]',
                'path = "C:/ProgramData/RiceMillAgent/store.sqlite3"',
                'key = ""                       # ricemill-agent new-store-key, then protect it',
                '',
                '[scale]',
                'id = "'.$s->id.'"              # '.$s->code.' · '.$s->name,
                'resolution_kg = "'.$resolution.'"',
                'min_stable_samples = 6',
                '',
                '[indicator]',
                'type = "serial"                # ricemill-agent --config agent.toml detect --write fills this section',
                'port = "COM3"',
                'baudrate = 9600',
                '',
                '[camera]',
                'type = "none"                  # usb | rtsp: see the Connect the camera step',
            ]);

            return ['id' => (string) $s->id, 'code' => $s->code, 'name' => $s->name, 'resolution_kg' => $resolution, 'status' => $s->status, 'toml' => $toml];
        })->values()->all();

        return [
            'server' => $server,
            'scales' => $scales,
            'agents' => $agents->map(fn (Device $d) => [
                'device_uid' => $d->device_uid, 'name' => (string) $d->name, 'username' => (string) ($d->loginAccount->username ?? '—'),
                'status' => $d->status, 'last_seen' => $d->last_seen_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? 'never',
            ])->values()->all(),
        ];
    }

    /**
     * The steps. `auto` = what the system sees (true done, false not yet, null = cannot tell: tick by hand).
     *
     * @return list<array{title: string, device: string, intro: string, do: list<string>, commands: list<string>, links: list<array{label: string, url: string}>, auto: bool|null, done: bool}>
     */
    public function steps(): array
    {
        $scales = $this->scales();
        $agent = Device::query()->where('platform', 'agent')->where('status', 'active')->orderByDesc('last_seen_at')->first();
        $server = rtrim((string) config('app.url'), '/');
        $steps = [
            [
                'title' => 'Check the indicator has a data port', 'device' => 'Weighing indicator',
                'intro' => 'The indicator is the box that shows the weight (for example the PF-7E). It must send the weight out through a cable.',
                'do' => [
                    'Look at the back and underneath for a 9-pin socket marked RS232 (sometimes a round socket marked COM).',
                    'Note the model and the smallest weight step on the display (division), e.g. 10 kg on a truck weighbridge, 0.1 kg on a platform scale.',
                    'No RS232 socket? This unit cannot send its weight. Ask the seller for the RS-232 version or board, or use an indicator with RS-232 (e.g. XK3190-A12E).',
                ],
                'commands' => [], 'links' => [], 'auto' => null,
            ],
            [
                'title' => 'Switch on continuous output', 'device' => 'Weighing indicator',
                'intro' => 'The indicator must send the weight all the time, not only when a key is pressed.',
                'do' => [
                    'Open the indicator set-up menu: often hold Tare or Zero while switching it on, or press Func / Set (see its manual or ask the scale engineer).',
                    'Find the communication setting, shown as bAud, COM, tF, Pr or CO.',
                    'Choose CONTINUOUS (often tF0, cont or 1) and 9600 baud. Save and switch the indicator off and on.',
                    'Not sure? Continue: the agent tries every speed and format in step 8.',
                ],
                'commands' => [], 'links' => [], 'auto' => null,
            ],
            [
                'title' => 'Connect the cable to the PC', 'device' => 'Cable and weighbridge PC',
                'intro' => 'A Windows 10/11 PC near the weighbridge, always on and on the internet (a UPS is advised).',
                'do' => [
                    'Plug an RS-232 cable from the indicator\'s RS232 socket into the PC (at most about 15 m).',
                    'No COM port on the PC? Use a USB-to-RS232 adapter (CH340, PL2303 or FTDI) and install its driver.',
                    'Open Device Manager → Ports (COM & LPT): the port appears, e.g. COM3.',
                    'If later nothing is received, use a null-modem cable or adapter (wires 2 and 3 crossed).',
                ],
                'commands' => [], 'links' => [], 'auto' => null,
            ],
            [
                'title' => 'Add the weighbridge in the ERP', 'device' => 'ERP',
                'intro' => 'The scale the readings belong to.',
                'do' => [
                    'Weighbridges → New: code (e.g. WB1), name, capacity in kg and division (the step from step 1).',
                    'Add the stamping (verification) certificate with its dates, then open the weighbridge and copy its id.',
                ],
                'commands' => [], 'links' => [['label' => 'Weighbridges', 'url' => ScaleResource::getUrl()]],
                'auto' => Scale::query()->where('status', 'active')->exists(),
            ],
            [
                'title' => 'Create the PC\'s login', 'device' => 'ERP',
                'intro' => 'The weighbridge PC signs in with its own login, used only by the agent.',
                'do' => [
                    'Users → New: role Weighbridge / Gate Operator, username e.g. wb.agent.',
                    'Sign in once with it to set its own password; keep the password for step 7.',
                ],
                'commands' => [], 'links' => [['label' => 'Users', 'url' => LoginAccountResource::getUrl()]],
                'auto' => LoginAccount::query()->where('status', LoginAccount::STATUS_ACTIVE)->whereHas('role', fn ($q) => $q->where('code', RoleTemplates::GATE_OPERATOR))->exists(),
            ],
            [
                'title' => 'Register the gate agent', 'device' => 'ERP',
                'intro' => 'This gives the PC its identity and a secret for signed messages.',
                'do' => [
                    'Security → Devices → Register gate agent, choose the PC\'s login.',
                    'Copy the device_uid and the signing secret now: the secret is shown only once.',
                ],
                'commands' => [], 'links' => [['label' => 'Devices', 'url' => DeviceResource::getUrl()]],
                'auto' => $agent !== null,
            ],
            [
                'title' => 'Install the gate agent on the PC', 'device' => 'Weighbridge PC',
                'intro' => 'One script installs it and asks for the details from steps 4–6 (secrets are typed hidden).',
                'do' => [
                    'Install Python 3.12 from python.org (tick "Add python.exe to PATH").',
                    'Copy the agent folder to the PC, e.g. C:\RiceMillAgent, and open PowerShell there.',
                    'Run the set-up script. ERP address to give: '.$server,
                ],
                'commands' => ['powershell -ExecutionPolicy Bypass -File .\setup-windows.ps1'],
                'links' => [],
                'auto' => $agent?->last_seen_at !== null,
            ],
            [
                'title' => 'Find the indicator', 'device' => 'Weighbridge PC',
                'intro' => 'The agent listens on the COM ports at every speed and format and writes the settings it finds (the script does this too).',
                'do' => [
                    'Keep a weight on the platform (or press on it) while it listens.',
                    'It reports e.g. "COM3 at 9600 baud, format reversed" and saves it in agent.toml.',
                    'Then watch the readings: they must follow the indicator display.',
                ],
                'commands' => [
                    '.venv\Scripts\ricemill-agent ports',
                    '.venv\Scripts\ricemill-agent --config agent.toml detect --write',
                    '.venv\Scripts\ricemill-agent --config agent.toml watch',
                ],
                'links' => [],
                'auto' => collect($scales)->contains(fn ($s) => $s['kg'] !== null) ? true : null,
            ],
            [
                'title' => 'Connect the camera', 'device' => 'Camera',
                'intro' => 'A photo of the vehicle is taken at each weighing (USB webcam, or a CCTV / IP camera).',
                'do' => [
                    'Install ffmpeg: winget install Gyan.FFmpeg',
                    'USB webcam: list the cameras and put the name in agent.toml: [camera] type = "usb", device = "…".',
                    'CCTV / IP camera: give it a fixed address; [camera] type = "rtsp", url = "rtsp://user:password@camera-ip:554/…".',
                    'Take a test photo and check the platform and number plate are in view.',
                ],
                'commands' => [
                    '.venv\Scripts\ricemill-agent cameras',
                    '.venv\Scripts\ricemill-agent --config agent.toml camera-test',
                ],
                'links' => [],
                'auto' => Weighment::query()->whereNotNull('photo_sha256')->where('captured_at', '>=', now()->subDays(30))->exists() ? true : null,
            ],
            [
                'title' => 'Test the connection and the live weight', 'device' => 'Weighbridge PC and ERP',
                'intro' => 'The PC talks to the ERP, and the weight appears on the screens.',
                'do' => [
                    'Run check: it tests every step (settings, indicator, ERP, sign-in, live weight) and says in plain words what to fix.',
                    'Run sync: it must finish without an error.',
                    'Run live and open Live weight: the number follows the indicator within a second and shows Stable when it settles.',
                    'Check with a known weight.',
                ],
                'commands' => ['.venv\Scripts\ricemill-agent --config agent.toml check', '.venv\Scripts\ricemill-agent --config agent.toml sync', '.venv\Scripts\ricemill-agent --config agent.toml live'],
                'links' => [['label' => 'Live weight', 'url' => LiveWeightPage::getUrl()]],
                'auto' => collect($scales)->contains(fn ($s) => $s['live']),
            ],
            [
                'title' => 'Keep it running', 'device' => 'Weighbridge PC',
                'intro' => 'Two Windows Task Scheduler tasks, as the PC\'s service account, "whether the user is logged on or not".',
                'do' => [
                    'Task 1, at startup, restart on failure every minute: the live command below.',
                    'Task 2, every minute: the sync command below (sends recorded weights; works offline and catches up).',
                ],
                'commands' => [
                    'C:\RiceMillAgent\.venv\Scripts\ricemill-agent.exe --config C:\RiceMillAgent\agent.toml live',
                    'C:\RiceMillAgent\.venv\Scripts\ricemill-agent.exe --config C:\RiceMillAgent\agent.toml sync',
                ],
                'links' => [],
                'auto' => $agent?->last_seen_at !== null && $agent->last_seen_at->greaterThan(now()->subMinutes(10)),
            ],
            [
                'title' => 'Record and check weights', 'device' => 'ERP',
                'intro' => 'Ready to work.',
                'do' => [
                    'Live weight: choose the vehicle on the platform and press Record gross / Record tare when it shows Stable.',
                    'Weight checks: the Super Admin (or the Owner) marks each weight OK or queries it.',
                ],
                'commands' => [], 'links' => [['label' => 'Live weight', 'url' => LiveWeightPage::getUrl()]], 'auto' => null,
            ],
        ];

        foreach ($steps as $i => $s) {
            $steps[$i]['done'] = $s['auto'] === true || in_array($i + 1, $this->ticked, true);
        }

        return $steps;
    }

    public function firstOpenStep(): int
    {
        foreach ($this->steps() as $i => $s) {
            if (! $s['done']) {
                return $i + 1;
            }
        }

        return 1;
    }

    public function go(int $step): void
    {
        $this->step = max(1, min(count($this->steps()), $step));
    }

    public function next(): void
    {
        $this->go($this->step + 1);
    }

    public function back(): void
    {
        $this->go($this->step - 1);
    }

    /** Ticks or unticks a step by hand (those the system cannot see), then moves on. */
    public function toggle(int $step): void
    {
        $this->ticked = in_array($step, $this->ticked, true)
            ? array_values(array_diff($this->ticked, [$step]))
            : [...$this->ticked, $step];
        session(['connect-weighbridge.ticked' => $this->ticked]);
        if (in_array($step, $this->ticked, true)) {
            $this->next();
        }
    }
}
