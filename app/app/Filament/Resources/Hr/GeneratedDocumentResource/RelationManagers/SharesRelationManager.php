<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\GeneratedDocumentResource\RelationManagers;

use App\Domain\Documents\Engine\DocumentSharing;
use App\Filament\Support\DocumentActions;
use App\Models\Documents\DocumentShare;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The links this document was shared with: until when, how often opened, and revoke. */
class SharesRelationManager extends RelationManager
{
    protected static string $relationship = 'shares';

    protected static ?string $title = 'Shared links';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Shared')->dateTime('d M Y H:i', 'Asia/Karachi'),
                TextColumn::make('channel')->formatStateUsing(fn (string $state) => DocumentShare::CHANNELS[$state] ?? $state),
                TextColumn::make('recipient_masked')->label('To')->state(fn (DocumentShare $r) => self::mask($r->recipient)),
                TextColumn::make('expires_at')->label('Open until')->dateTime('d M Y H:i', 'Asia/Karachi'),
                TextColumn::make('open_count')->label('Opened')->formatStateUsing(fn (DocumentShare $r) => $r->open_count.($r->max_opens !== null ? ' of '.$r->max_opens : '')),
                IconColumn::make('protected')->label('CNIC password')->boolean(),
                TextColumn::make('state')->badge()->state(fn (DocumentShare $r) => $r->revoked_at !== null ? 'revoked' : ($r->isUsable() ? 'open' : 'closed'))
                    ->color(fn (string $state) => $state === 'open' ? 'success' : 'gray'),
            ])
            ->actions([
                Action::make('revoke')->icon('heroicon-o-no-symbol')->color('danger')->requiresConfirmation()
                    ->visible(fn (DocumentShare $r) => $r->revoked_at === null && DocumentActions::allowed('hr.documents.share'))
                    ->action(function (DocumentShare $record): void {
                        app(DocumentSharing::class)->revoke($record);
                        Notification::make()->title('Link revoked: it no longer opens.')->success()->send();
                    }),
            ]);
    }

    private static function mask(?string $to): string
    {
        if ($to === null || $to === '') {
            return '—';
        }
        if (str_contains($to, '@')) {
            [$user, $domain] = explode('@', $to, 2);

            return mb_substr($user, 0, 2).'…@'.$domain;
        }

        return substr($to, 0, 6).'****'.substr($to, -3);
    }
}
