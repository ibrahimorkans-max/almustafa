<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\ExpenseCategoryResource\Pages;

use App\Domain\Accounts\ExpenseCategories;
use App\Filament\Resources\Accounts\ExpenseCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseCategories extends ListRecords
{
    protected static string $resource = ExpenseCategoryResource::class;

    public function mount(): void
    {
        // The standard categories (travel, fuel, meals …) and their ledgers, the first time.
        app(ExpenseCategories::class)->installDefaults();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
