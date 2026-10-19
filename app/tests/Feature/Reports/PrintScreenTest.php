<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ListGateEntries;
use App\Filament\Resources\Masters\PartyResource\Pages\EditParty;
use App\Filament\Resources\Procurement\SaudaResource\Pages\ListSaudas;
use App\Filament\Resources\Procurement\SaudaResource\Pages\ViewSauda;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Procurement\Sauda;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\DemoMastersSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('AMR');
    $this->seed(DemoMastersSeeder::class);
    $this->seed(DemoActivitySeeder::class);
    Make::reset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Make::account($this->entity, 'SUPER_ADMIN');
});

function psSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

it('puts a Print button on every list and record screen', function () {
    psSignIn($this, $this->admin);

    $this->get(GateEntryResource::getUrl())->assertOk()->assertSee('wire:click="printScreen"', false);
    $this->get(ViewSauda::getUrl(['record' => Sauda::query()->firstOrFail()]))->assertOk()->assertSee('wire:click="printScreen"', false);
});

it('prints a list exactly as filtered and searched on the screen', function () {
    psSignIn($this, $this->admin);
    $numbers = Sauda::query()->orderBy('number')->pluck('number');

    Livewire::test(ListSaudas::class)->call('printScreen')->assertFileDownloaded();
    expect(PdfRenderer::$lastHtml)->toContain($numbers[0])->toContain($numbers[1])->toContain('2 record(s)');

    Livewire::test(ListSaudas::class)->set('tableSearch', $numbers[1])->call('printScreen')->assertFileDownloaded();
    expect(PdfRenderer::$lastHtml)->toContain($numbers[1])->not->toContain($numbers[0])->toContain('Search: '.$numbers[1]);

    Livewire::test(ListGateEntries::class)->call('printScreen')->assertFileDownloaded();
    expect(PdfRenderer::$lastHtml)->toContain('MNA234411');
});

it('prints a record\'s own paper, or a record sheet when it has none', function () {
    psSignIn($this, $this->admin);
    $sauda = Sauda::query()->orderBy('number')->firstOrFail();

    Livewire::test(ViewSauda::class, ['record' => $sauda->id])->call('printScreen')->assertFileDownloaded();
    expect(PdfRenderer::$lastHtml)->toContain('PURCHASE CONTRACT (SAUDA)');

    $party = Party::query()->where('code', 'AMR-F001')->firstOrFail();
    Livewire::test(EditParty::class, ['record' => $party->id])->call('printScreen')->assertFileDownloaded();
    expect(PdfRenderer::$lastHtml)->toContain('Muhammad Aslam (demo grower)')->toContain('Checked by (second person)');
});
