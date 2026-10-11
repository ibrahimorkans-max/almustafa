<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\ExpenseResource\Pages;

use App\Domain\Accounts\Expenses;
use App\Filament\Resources\Accounts\ExpenseResource;
use App\Filament\Resources\Accounts\RecurringExpenseResource;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('templates')->label('Recurring expenses')->icon('heroicon-o-arrow-path')->color('gray')
                ->url(fn () => RecurringExpenseResource::getUrl()),
            // The scheduler drafts these every morning; this does it now (it never makes a month twice).
            Action::make('draft')->label('Draft due recurring now')->icon('heroicon-o-document-duplicate')->color('gray')
                ->visible(fn () => Filament::auth()->user() instanceof LoginAccount && Filament::auth()->user()->hasPermission('ledger.post'))
                ->action(function (): void {
                    $n = app(Expenses::class)->draftRecurring();
                    Notification::make()->title($n === 0 ? 'Nothing due.' : "{$n} draft(s) made; post them after checking the amounts.")->success()->send();
                }),
            CreateAction::make()->label('New expense or income'),
        ];
    }
}
