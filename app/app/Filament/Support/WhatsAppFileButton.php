<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Messaging\WhatsAppFiles;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Masters\Party;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Model;

/**
 * "Send on WhatsApp" for a printed document: the PDF goes as a file on the mill's connected WhatsApp with the
 * short message from the mill's template (App\Domain\Messaging\WhatsAppFiles), which can be changed before
 * sending. Shown only while WhatsApp is connected.
 *
 * The resolver gets the record and gives: party (or null), phone (or null: the party's), document (its kind,
 * e.g. "weighment slip"), number, file (name) and pdf (a closure making the bytes).
 */
final class WhatsAppFileButton
{
    /** @param  Closure(never): array{party: Party|null, phone?: string|null, name?: string|null, document: string, number: string, file: string, pdf: Closure(): string}  $resolve  (the record) => what to send */
    public static function header(string $name, Closure $resolve, ?Closure $when = null): Action
    {
        return self::configure(Action::make($name), $resolve, $when);
    }

    /** @param  Closure(never): array{party: Party|null, phone?: string|null, name?: string|null, document: string, number: string, file: string, pdf: Closure(): string}  $resolve  (the record) => what to send */
    public static function row(string $name, Closure $resolve, ?Closure $when = null): TableAction
    {
        return self::configure(TableAction::make($name), $resolve, $when);
    }

    /**
     * @template T of Action|TableAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configure(Action|TableAction $action, Closure $resolve, ?Closure $when): Action|TableAction
    {
        return $action->label('Send on WhatsApp')->icon('heroicon-o-paper-airplane')->color('success')
            ->visible(fn (?Model $record = null) => WhatsAppFiles::ready(app(TenantContext::class)->entityId()) && ($when === null || $when($record)))
            ->modalHeading(fn (Model $record) => 'Send '.$resolve($record)['document'].' '.$resolve($record)['number'].' on WhatsApp')
            ->modalDescription('The PDF goes as a file with this message, from the mill\'s WhatsApp number. The message comes from the mill\'s template (Administration → Integrations) and can be changed here.')
            ->modalSubmitActionLabel('Send')
            ->fillForm(function (Model $record) use ($resolve): array {
                $r = $resolve($record);
                $party = $r['party'] ?? null;

                return [
                    'phone' => $r['phone'] ?? $party?->phone,
                    'message' => app(WhatsAppFiles::class)->message(app(TenantContext::class)->entityId(), $r['name'] ?? $party?->name, $r['document'], $r['number']),
                ];
            })
            ->form(fn (Model $record) => [
                Placeholder::make('file')->label('File')->content(fn () => '📎 '.$resolve($record)['file']),
                TextInput::make('phone')->label('WhatsApp number')->required()->tel()->placeholder('0300 1234567')->maxLength(20),
                Textarea::make('message')->label('Message')->required()->rows(3)->maxLength(900),
            ])
            ->action(function (Model $record, array $data, Action|TableAction $action) use ($resolve): void {
                $r = $resolve($record);
                try {
                    $sent = app(WhatsAppFiles::class)->send($r['party'] ?? null, (string) $data['phone'], ($r['pdf'])(), $r['file'], (string) $data['message'], $r['document']);
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $sent['ok']
                    ? Notification::make()->success()->title(ucfirst($r['document']).' sent on WhatsApp.')->body('Delivery shows in Chats once WhatsApp reports it.')->send()
                    : Notification::make()->danger()->title((string) $sent['error'])->persistent()->send();
            });
    }
}
