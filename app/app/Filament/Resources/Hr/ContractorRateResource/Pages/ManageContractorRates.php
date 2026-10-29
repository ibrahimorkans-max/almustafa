<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\ContractorRateResource\Pages;

use App\Domain\Approvals\SelfApproval;
use App\Domain\Hr\ContractorVehicles;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\ContractorBillResource;
use App\Filament\Resources\Hr\ContractorRateResource;
use App\Models\Hr\ContractorRate;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageContractorRates extends ManageRecords
{
    protected static string $resource = ContractorRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The contractor's own vehicle list: a vehicle works for one contractor at a time.
            Action::make('contractorVehicles')->label('Contractor vehicles')->icon('heroicon-o-truck')->color('gray')
                ->visible(fn () => ContractorRateResource::canCreate())
                ->modalDescription('The vehicles a contractor works with. They load when the contractor is chosen at the gate and on rates. A vehicle of another contractor cannot be added.')
                ->modalSubmitActionLabel('Save')
                ->form([
                    Select::make('party_id')->label('Contractor')->required()->searchable()->live()->options(fn () => ContractorBillResource::contractors()),
                    Placeholder::make('list')->label('Vehicles of this contractor now')->visible(fn (Get $get) => filled($get('party_id')))
                        ->content(fn (Get $get) => implode(', ', ContractorRateResource::vehicleOptions($get('party_id'))) ?: 'None yet.'),
                    Select::make('vehicle_id')->label('Add a registered vehicle')->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Vehicle::query()->whereNull('contractor_party_id')
                            ->where('registration_no', 'ilike', '%'.str_replace(['%', '_', ' ', '-'], '', strtoupper($search)).'%')->limit(30)->pluck('registration_no', 'id')->all())
                        ->getOptionLabelUsing(fn ($value) => Vehicle::query()->whereKey($value)->value('registration_no')),
                    TextInput::make('registration_no')->label('… or a new vehicle number')->maxLength(20),
                    Select::make('vehicle_type')->label('Kind of new vehicle')->options(Vehicle::LABELS)->default('truck'),
                    Select::make('remove')->label('Take a vehicle off (Owner / Super Admin)')->multiple()
                        ->visible(fn (Get $get) => filled($get('party_id')) && SelfApproval::allowed(Filament::auth()->user()))
                        ->options(fn (Get $get) => ContractorRateResource::vehicleOptions($get('party_id'))),
                ])
                ->action(function (array $data): void {
                    $svc = app(ContractorVehicles::class);
                    $party = Party::query()->findOrFail($data['party_id']);
                    try {
                        $done = [];
                        if (filled($data['vehicle_id'] ?? null)) {
                            $done[] = $svc->linkVehicle(Vehicle::query()->findOrFail($data['vehicle_id']), $party)->registration_no;
                        }
                        if (filled($data['registration_no'] ?? null)) {
                            $done[] = $svc->addVehicle($party, (string) $data['registration_no'], $data['vehicle_type'] ?? null)->registration_no;
                        }
                        foreach ((array) ($data['remove'] ?? []) as $id) {
                            $svc->linkVehicle(Vehicle::query()->findOrFail($id), null);
                        }
                        Notification::make()->success()->title($done === [] ? 'Saved.' : implode(', ', $done).' added to '.$party->name.'.')
                            ->body(($data['remove'] ?? []) !== [] ? count($data['remove']).' vehicle(s) taken off.' : null)->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            CreateAction::make()->label('Add a rate')
                ->using(fn (array $data, CreateAction $action) => ContractorRateResource::guarded(fn () => ContractorRate::query()->create($data), $action)),
        ];
    }
}
