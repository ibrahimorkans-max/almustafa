<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ApprovalRequestResource\Pages;
use App\Models\ApprovalRequest;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ApprovalRequestResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Approvals';

    protected static ?string $slug = 'approvals';

    protected static ?int $navigationSort = 40;

    public static function getNavigationBadge(): ?string
    {
        $count = ApprovalRequest::query()->where('status', ApprovalRequest::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            ApprovalRequest::STATUS_PENDING => 'warning',
            ApprovalRequest::STATUS_EXECUTED => 'success',
            ApprovalRequest::STATUS_FAILED, ApprovalRequest::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('action')->badge(),
                TextColumn::make('summary')->wrap()->searchable(),
                TextColumn::make('maker.username')->label('Requested by'),
                TextColumn::make('status')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextColumn::make('checker.username')->label('Decided by')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'executed' => 'Executed', 'rejected' => 'Rejected',
                    'failed' => 'Failed', 'cancelled' => 'Cancelled',
                ])->default('pending'),
            ])
            ->actions([ViewAction::make()])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(3)->schema([
                TextEntry::make('summary')->columnSpanFull(),
                TextEntry::make('action')->badge(),
                TextEntry::make('status')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextEntry::make('expires_at')->dateTime(),
                TextEntry::make('maker.username')->label('Requested by'),
                TextEntry::make('created_at')->label('Requested at')->dateTime(),
                TextEntry::make('reason')->columnSpanFull(),
            ]),
            Section::make('Change')->schema([
                TextEntry::make('added')->label('Permissions added')
                    ->state(fn (ApprovalRequest $r) => array_values(array_diff($r->payload['permissions'] ?? [], $r->payload['before'] ?? [])) ?: ['—'])
                    ->listWithLineBreaks()->badge()->color('success')
                    ->visible(fn (ApprovalRequest $r) => isset($r->payload['before'])),
                TextEntry::make('removed')->label('Permissions removed')
                    ->state(fn (ApprovalRequest $r) => array_values(array_diff($r->payload['before'] ?? [], $r->payload['permissions'] ?? [])) ?: ['—'])
                    ->listWithLineBreaks()->badge()->color('danger')
                    ->visible(fn (ApprovalRequest $r) => isset($r->payload['before'])),
                KeyValueEntry::make('payload_view')->label('Payload')
                    ->state(fn (ApprovalRequest $r) => collect($r->payload)->map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v))->all())
                    ->visible(fn (ApprovalRequest $r) => ! isset($r->payload['before'])),
            ])->columns(2),
            Section::make('Decision')->columns(3)->visible(fn (ApprovalRequest $r) => $r->decided_at !== null)->schema([
                TextEntry::make('checker.username')->label('Decided by'),
                TextEntry::make('decided_at')->dateTime(),
                TextEntry::make('executed_at')->dateTime()->placeholder('—'),
                TextEntry::make('decision_note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('error')->columnSpanFull()->color('danger')->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalRequests::route('/'),
            'view' => Pages\ViewApprovalRequest::route('/{record}'),
        ];
    }
}
