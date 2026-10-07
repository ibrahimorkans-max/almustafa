<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Read-only audit viewer. There is no create, edit or delete anywhere. */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $slug = 'audit-log';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('When (UTC)')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('action')->badge()->searchable(),
                TextColumn::make('subject_type')->label('Subject')->placeholder('—')
                    ->description(fn (AuditLog $r) => $r->subject_id),
                TextColumn::make('actor.username')->label('Who')->placeholder(fn (AuditLog $r) => $r->actor_login_account_id ? 'other entity' : 'system'),
                IconColumn::make('acting_as_super_admin')->label('SA')->boolean(),
                TextColumn::make('ip')->label('IP')->placeholder('—'),
                TextColumn::make('seq')->label('#')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                Filter::make('action')
                    ->form([TextInput::make('action')->placeholder('e.g. auth.login')])
                    ->query(fn (Builder $q, array $data) => $q->when($data['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))),
                Filter::make('period')
                    ->form([DatePicker::make('from'), DatePicker::make('to')])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
                        ->when($data['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<', Carbon::parse($v)->addDay()))),
            ])
            ->actions([ViewAction::make()])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $kv = fn (?array $values) => collect($values ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) ($v ?? 'null') : json_encode($v))->all();

        return $infolist->schema([
            Section::make()->columns(3)->schema([
                TextEntry::make('occurred_at')->dateTime('Y-m-d H:i:s.u')->label('When (UTC)'),
                TextEntry::make('action')->badge(),
                TextEntry::make('actor.username')->label('Who')->placeholder('system / other entity'),
                TextEntry::make('subject_type')->placeholder('—'),
                TextEntry::make('subject_id')->placeholder('—'),
                TextEntry::make('ip')->placeholder('—'),
                TextEntry::make('device_id')->placeholder('—'),
                TextEntry::make('user_agent')->columnSpan(2)->placeholder('—'),
            ]),
            Section::make('Values')->columns(2)->schema([
                KeyValueEntry::make('old_values')->label('Old')->state(fn (AuditLog $r) => $kv($r->old_values)),
                KeyValueEntry::make('new_values')->label('New')->state(fn (AuditLog $r) => $kv($r->new_values)),
                KeyValueEntry::make('meta')->label('Details')->state(fn (AuditLog $r) => $kv($r->meta))->columnSpanFull(),
            ]),
            Section::make('Integrity')->columns(3)->collapsed()->schema([
                TextEntry::make('chain'),
                TextEntry::make('seq'),
                TextEntry::make('prev_hash')->fontFamily('mono')->size('xs'),
                TextEntry::make('hash')->fontFamily('mono')->size('xs')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
