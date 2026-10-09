<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SyncConflictResource\Pages;
use App\Models\SyncConflict;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Supervisor review queue + full conflict log for offline sync. */
class SyncConflictResource extends Resource
{
    protected static ?string $model = SyncConflict::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Masters';

    protected static ?string $navigationLabel = 'Sync review queue';

    protected static ?string $slug = 'sync-review';

    protected static ?int $navigationSort = 90;

    public static function getNavigationBadge(): ?string
    {
        $open = SyncConflict::query()->where('review_status', SyncConflict::REVIEW_OPEN)->count();

        return $open > 0 ? (string) $open : null;
    }

    /** @return array<string, string> */
    public static function reasonLabels(): array
    {
        return [
            'duplicate_value' => 'Duplicate code / number / STRN',
            'archived_on_server' => 'Record was archived on the server',
            'older_than_offline_limit' => 'Device was offline too long',
            'version_mismatch' => 'Edited on two devices at once',
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('reason')->formatStateUsing(fn (string $state) => static::reasonLabels()[$state] ?? $state),
                TextColumn::make('resolution')->badge()->color(fn (string $state) => $state === 'needs_review' ? 'warning' : 'gray'),
                TextColumn::make('review_status')->label('Review')->badge()->placeholder('logged only')
                    ->color(fn (?string $state) => $state === 'open' ? 'danger' : 'success'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('review_status')->label('Review')->options(['open' => 'Open', 'applied' => 'Applied', 'dismissed' => 'Dismissed'])->default('open'),
                SelectFilter::make('resolution')->options(['needs_review' => 'Needs review', 'client_won' => 'Device won', 'server_won' => 'Server won']),
            ])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $kv = fn (?array $values) => collect($values ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) ($v ?? '—') : json_encode($v))->all();

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('type')->badge(),
                TextEntry::make('reason')->formatStateUsing(fn (string $state) => static::reasonLabels()[$state] ?? $state),
                TextEntry::make('resolution')->badge(),
                TextEntry::make('review_status')->badge()->placeholder('logged only'),
                TextEntry::make('record_id')->placeholder('—')->columnSpan(2),
                TextEntry::make('sync_event_id')->label('Device event')->columnSpan(2),
            ]),
            Section::make('Data')->columns(2)->schema([
                KeyValueEntry::make('client_payload')->label('From device')->state(fn (SyncConflict $r) => $kv($r->client_payload)),
                KeyValueEntry::make('server_snapshot')->label('On server at the time')->state(fn (SyncConflict $r) => $kv(
                    collect($r->server_snapshot ?? [])->except(['strn', 'ntn', 'cnic', 'strn_index', 'ntn_index', 'cnic_index'])->all(),
                )),
            ]),
            Section::make('Review')->columns(3)->visible(fn (SyncConflict $r) => $r->reviewed_at !== null)->schema([
                TextEntry::make('reviewed_at')->dateTime(),
                TextEntry::make('review_note')->columnSpan(2),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncConflicts::route('/'),
            'view' => Pages\ViewSyncConflict::route('/{record}'),
        ];
    }
}
