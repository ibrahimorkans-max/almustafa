<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Gate\GatePolicy;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Support\QuickLabTest;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * One vehicle's own page to add its lab test (Gate → Lab tests to add → the vehicle): what it is (token, vehicle,
 * party, item, bags, loaded weight), one box per lab test with its limits, the verdict by the rules as the results
 * are typed, and the decision for someone who may decide. The rules are GateService::recordLabTest and decideLab.
 *
 * @property Form $form
 */
class LabTestEntryPage extends Page implements HasForms
{
    use InteractsWithForms;

    /** The vehicle comes as ?entry=…; without one, the page points to the list of lab tests to add. */
    protected static ?string $slug = 'gate/lab-test';

    protected static string $view = 'filament.pages.lab-test-entry';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public string $entryId = '';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('lab.test');
    }

    public function mount(?string $entry = null): void
    {
        $entry ??= (string) request()->query('entry', '');
        $found = $entry === '' ? null : GateEntry::query()->with(['party', 'product'])->where('direction', 'inbound')->where('purpose', '!=', 'other')->find($entry);
        if ($found === null) {
            return;   // no vehicle chosen: the page says so, with the link to the list
        }
        $this->entryId = $found->id;
        // An earlier result is shown to correct it.
        $last = LabTest::query()->where('gate_entry_id', $found->id)->latestFirst()->first();
        $fill = ['entry' => $found->id, 'decision' => 'later'];
        foreach ((array) ($last->results ?? []) as $code => $bp) {
            $fill['r_'.$code] = rtrim(rtrim(number_format(((int) $bp) / 100, 2, '.', ''), '0'), '.');
        }
        $this->form->fill($fill);
    }

    public function getTitle(): string
    {
        if ($this->entryId === '') {
            return 'Lab test';
        }
        $e = $this->entry();

        return 'Lab test · '.($e->token_serial !== null || $e->token_no !== null ? 'Token '.$e->tokenLabel().' · ' : '').$e->vehicleLabel();
    }

    public function form(Form $form): Form
    {
        return $form->schema(QuickLabTest::schema(false))->statePath('data');
    }

    public function entry(): GateEntry
    {
        /** @var GateEntry */
        return GateEntry::query()->with(['party', 'product'])->findOrFail($this->entryId);
    }

    /** Whether the lab result can still be added (it is not unloaded, or weighed when the lab comes first). */
    public function open(): bool
    {
        if ($this->entryId === '') {
            return false;
        }
        $e = $this->entry();

        return $e->isOpen() && GatePolicy::labOpen($e) && ! in_array($e->status, ['lab_accepted', 'lab_rejected'], true);
    }

    public function hasResult(): bool
    {
        return LabTest::query()->where('gate_entry_id', $this->entryId)->exists();
    }

    public function save(): void
    {
        if (! $this->open()) {
            Notification::make()->warning()->title('The lab has already decided on this lot.')->send();

            return;
        }
        $data = $this->form->getState();
        $data['entry'] = $this->entryId;
        try {
            $entry = QuickLabTest::save($data);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return;
        }
        QuickLabTest::saved($entry);
        $this->redirect(LabQueuePage::getUrl());
    }

    /** @return array<string, string> the vehicle's details shown at the top */
    public function details(): array
    {
        $e = $this->entry();
        $kg = fn ($v) => $v === null ? '—' : number_format((float) $v, 0).' kg ('.number_format((float) $v / 40, 2).' maund)';

        return array_filter([
            'Token' => $e->token_serial !== null || $e->token_no !== null ? $e->tokenLabel() : null,
            'Vehicle' => $e->vehicleLabel(),
            'Type' => $e->jack_type !== null ? $e->jackLabel() : null,
            'Party' => $e->partyLabel() ?? '—',
            'Item' => $e->itemLabel(),
            'Bags' => $e->bag_count !== null ? (string) $e->bag_count : null,
            'Loaded weight' => $e->gross_kg !== null ? $kg($e->gross_kg) : 'Not weighed yet',
            'Arrived' => $e->arrived_at?->timezone('Asia/Karachi')->format('d M Y, h:i A'),
            'Status' => str_replace('_', ' ', ucfirst($e->status)),
        ], fn ($v) => $v !== null);
    }

    public function vehicleUrl(): string
    {
        return GateEntryResource::getUrl('view', ['record' => $this->entryId]);
    }
}
