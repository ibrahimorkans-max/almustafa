<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\ContractorBillResource\Pages;

use App\Domain\Hr\ContractorBilling;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\ContractorBillResource;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListContractorBills extends ListRecords
{
    protected static string $resource = ContractorBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('workers')->label('Assign workers')->icon('heroicon-o-user-plus')->color('gray')
                ->visible(fn () => Filament::auth()->user() instanceof LoginAccount && Filament::auth()->user()->hasPermission('hr.employee.edit'))
                ->modalDescription('The workers a contractor supplies. Their attendance and piece work are billed to that contractor.')
                ->form([
                    Select::make('party_id')->label('Contractor')->required()->searchable()->live()->options(fn () => ContractorBillResource::contractors())
                        // Start from the workers already linked to the chosen contractor.
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('employees', $state === null ? []
                            : Employee::query()->where('contractor_party_id', $state)->pluck('id')->all())),
                    Select::make('employees')->label('Workers')->multiple()->searchable()
                        ->options(fn () => Employee::query()->with('person')->where('status', '!=', Employee::EXITED)->orderBy('code')->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}"])->all())
                        ->helperText('Workers taken off the list go back to being the mill\'s own staff.'),
                ])
                ->action(function (array $data): void {
                    $svc = app(ContractorBilling::class);
                    $party = Party::query()->findOrFail($data['party_id']);
                    $chosen = array_values((array) ($data['employees'] ?? []));
                    try {
                        $removed = Employee::query()->where('contractor_party_id', $party->id)->whereNotIn('id', $chosen)->pluck('id')->all();
                        if ($removed !== []) {
                            $svc->assignWorkers(null, $removed);
                        }
                        $n = $chosen === [] ? 0 : $svc->assignWorkers($party, $chosen);
                        Notification::make()->title("{$n} worker(s) linked to {$party->name}.")->success()->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            CreateAction::make()->label('Prepare bill'),
        ];
    }
}
