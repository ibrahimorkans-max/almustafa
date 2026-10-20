<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Filament\Resources\ApprovalRequestResource\Pages;
use App\Filament\Support\AttachmentsPanel;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use Filament\Actions\MountableAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action as TableAction;
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
            ->actions([
                ViewAction::make(),
                static::approveAction(TableAction::make('approve')),
                static::rejectAction(TableAction::make('reject')),
            ])
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
                    // Encrypted secrets (IBAN, Raast id) are never displayed; masked copies are.
                    ->state(fn (ApprovalRequest $r) => collect($r->payload)
                        ->reject(fn ($v, string $k) => str_ends_with($k, '_encrypted'))
                        ->map(fn ($v) => is_scalar($v) || $v === null ? (string) ($v ?? '—') : json_encode($v))->all())
                    ->visible(fn (ApprovalRequest $r) => ! isset($r->payload['before'])),
            ])->columns(2),
            Section::make('Decision')->columns(3)->visible(fn (ApprovalRequest $r) => $r->decided_at !== null)->schema([
                TextEntry::make('checker.username')->label('Decided by'),
                TextEntry::make('decided_at')->dateTime(),
                TextEntry::make('executed_at')->dateTime()->placeholder('—'),
                TextEntry::make('decision_note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('error')->columnSpanFull()->color('danger')->placeholder('—'),
            ]),
            Section::make('History')->collapsible()->schema([
                TextEntry::make('history')->hiddenLabel()->listWithLineBreaks()
                    ->state(fn (ApprovalRequest $r) => AuditLog::query()
                        ->where('subject_type', $r->getTable())->where('subject_id', $r->getKey())
                        ->orderBy('occurred_at')->orderBy('seq')->get()
                        ->map(fn (AuditLog $a) => sprintf('%s PKT · %s · %s%s',
                            $a->occurred_at->timezone('Asia/Karachi')->format('d M Y H:i:s'),
                            str_replace('approval.', '', $a->action),
                            LoginAccount::query()->whereKey($a->actor_login_account_id)->value('username') ?? 'system',
                            ($note = $a->meta['note'] ?? null) ? ' — '.$note : ''))
                        ->all() ?: ['—']),
            ]),
            AttachmentsPanel::section(),
        ]);
    }

    /**
     * Approve: shown on every pending request to people who may see it. When the rules block the
     * current user the button is disabled and says why — never silently hidden.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function approveAction(MountableAction $action, ?callable $after = null): MountableAction
    {
        return self::decisionButton($action, $after)->label('Approve')->color('success')->icon('heroicon-o-check')
            ->requiresConfirmation()
            ->modalDescription('The approved change is carried out immediately and recorded in the audit log.')
            ->form([Textarea::make('note')->label('Note (optional)')->maxLength(1000)])
            ->action(fn (ApprovalRequest $record, array $data) => static::runDecision(
                fn (ApprovalService $s, LoginAccount $me) => $s->approve($record, $me, $data['note'] ?? null), $after,
            ));
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function rejectAction(MountableAction $action, ?callable $after = null): MountableAction
    {
        return self::decisionButton($action, $after)->label('Reject')->color('danger')->icon('heroicon-o-x-mark')
            ->form([Textarea::make('note')->label('Reason')->required()->minLength(5)->maxLength(1000)])
            ->action(fn (ApprovalRequest $record, array $data) => static::runDecision(
                fn (ApprovalService $s, LoginAccount $me) => $s->reject($record, $me, $data['note']), $after,
            ));
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    private static function decisionButton(MountableAction $action, ?callable $after): MountableAction
    {
        $blocker = function (ApprovalRequest $record): ?string {
            $me = Filament::auth()->user();

            return $me instanceof LoginAccount ? app(ApprovalService::class)->decisionBlocker($me, $record) : 'Not signed in.';
        };

        return $action
            ->visible(fn (ApprovalRequest $record) => $record->status === ApprovalRequest::STATUS_PENDING)
            ->disabled(fn (ApprovalRequest $record) => $blocker($record) !== null)
            ->tooltip(fn (ApprovalRequest $record) => $blocker($record));
    }

    /** @param  callable(ApprovalService, LoginAccount): ApprovalRequest  $callback */
    public static function runDecision(callable $callback, ?callable $after = null): void
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();

        try {
            $result = $callback(app(ApprovalService::class), $me);
        } catch (ApprovalDenied $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $notification = Notification::make()->title('Request '.$result->status);
        ($result->status === ApprovalRequest::STATUS_FAILED ? $notification->danger()->body($result->error) : $notification->success())->send();
        if ($after !== null) {
            $after($result);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalRequests::route('/'),
            'view' => Pages\ViewApprovalRequest::route('/{record}'),
        ];
    }
}
