<?php

declare(strict_types=1);

use App\Filament\Resources\Hr\ContractorBillResource\Pages\ListContractorBills;
use App\Models\Masters\Party;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\HrFixtures;
use Tests\Support\Make;

/*
 * HR → Contractor bills → Assign workers: the links are saved, also when the workers are picked before
 * the contractor, and the screen says how many are linked.
 */
beforeEach(function () {
    $this->f = new HrFixtures(Make::entity('ACW'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app('auth')->forgetGuards();
    $this->actingAs($this->f->hr, 'web')->withSession(Make::webSession($this->f->hr));
    $this->f->as($this->f->hr);
    $this->contractor = Party::query()->create(['name' => 'Rafiq Labour Co', 'is_vendor' => true]);
});

it('saves the workers picked before the contractor is chosen', function () {
    $a = $this->f->employee();
    $b = $this->f->employee();
    $this->f->as($this->f->hr);

    Livewire::test(ListContractorBills::class)
        ->mountAction('workers')
        ->setActionData(['employees' => [$a->id, $b->id]])
        ->setActionData(['party_id' => $this->contractor->id])   // choosing the contractor keeps the picks
        ->assertActionDataSet(['employees' => [$a->id, $b->id]])
        ->callMountedAction()
        ->assertNotified('2 worker(s) linked to Rafiq Labour Co.');

    expect($a->fresh()->contractor_party_id)->toBe($this->contractor->id)->and($b->fresh()->contractor_party_id)->toBe($this->contractor->id);
});

it('takes a worker off the list, and warns when nobody is left', function () {
    $a = $this->f->employee(['contractor_party_id' => $this->contractor->id]);
    $this->f->as($this->f->hr);

    Livewire::test(ListContractorBills::class)
        ->callAction('workers', ['party_id' => $this->contractor->id, 'employees' => []])
        ->assertNotified('No workers are linked to Rafiq Labour Co now (1 taken off).');
    expect($a->fresh()->contractor_party_id)->toBeNull();
});
