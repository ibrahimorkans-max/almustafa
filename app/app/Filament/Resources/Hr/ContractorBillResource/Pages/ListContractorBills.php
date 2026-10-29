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
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
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
                        // Add the workers already linked to the chosen contractor to any picked before it (never drop a pick).
                        ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => $set('employees', array_values(array_unique([
                            ...array_values((array) $get('employees')),
                            ...($state === null ? [] : Employee::query()->where('contractor_party_id', $state)->pluck('id')->all()),
                        ])))),
                    Placeholder::make('linked_now')->label('Linked to this contractor now')
                        ->visible(fn (Get $get) => filled($get('party_id')))
                        ->content(fn (Get $get) => Employee::query()->with('person')->where('contractor_party_id', $get('party_id'))->orderBy('code')->get()
                            ->map(fn (Employee $e) => "{$e->code} {$e->name()}")->implode(', ') ?: 'Nobody yet.'),
                    Select::make('employees')->label('Workers')->multiple()->searchable()->preload()
                        ->options(fn () => Employee::query()->with(['person', 'contractor'])->where('status', '!=', Employee::EXITED)->orderBy('code')->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}".($e->contractor !== null ? ' (works for '.$e->contractor->name.')' : '')])->all())
                        // A worker of another contractor cannot be picked: take them off that contractor's list first.
                        ->disableOptionWhen(fn (string $value, Get $get) => in_array($value, Employee::query()->whereNotNull('contractor_party_id')
                            ->when(filled($get('party_id')), fn ($q) => $q->where('contractor_party_id', '!=', (string) $get('party_id')))->pluck('id')->all(), true))
                        ->helperText('The full list of this contractor\'s workers (daily and monthly). A worker of another contractor cannot be added until taken off that contractor\'s list. Workers taken off go back to being the mill\'s own staff.'),
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
                        $linked = Employee::query()->where('contractor_party_id', $party->id)->count();
                        if ($n === 0) {
                            Notification::make()->warning()->title("No workers are linked to {$party->name} now".($removed !== [] ? ' ('.count($removed).' taken off).' : '.'))
                                ->body('Choose the workers in the list, then save.')->send();

                            return;
                        }
                        Notification::make()->success()->title("{$linked} worker(s) linked to {$party->name}.")
                            ->body($removed !== [] ? count($removed).' taken off the list.' : null)->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            CreateAction::make()->label('Prepare bill'),
        ];
    }
}
