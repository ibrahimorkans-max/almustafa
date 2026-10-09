<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\EmployeeResource\RelationManagers;

use App\Domain\Hr\EmployeeDocuments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Hr\EmployeeDocument;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->formatStateUsing(fn (string $state) => EmployeeDocument::KINDS[$state] ?? $state),
                TextColumn::make('title'),
                TextColumn::make('valid_until')->date('d M Y')->placeholder('—')
                    ->color(fn (EmployeeDocument $r) => $r->valid_until !== null && $r->valid_until->isPast() ? 'danger' : null),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Uploaded')->dateTime('d M Y H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'superseded' => 'Superseded'])->default('active')])
            ->actions([
                Action::make('download')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (EmployeeDocument $r) => app(EmployeeDocuments::class)->mayOpen($r))
                    ->action(function (EmployeeDocument $r) {
                        try {
                            $file = app(EmployeeDocuments::class)->download($r);
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return null;
                        }

                        return response()->streamDownload(fn () => print $file['bytes'], $file['name'], ['Content-Type' => $file['mime']]);
                    }),
            ]);
    }
}
