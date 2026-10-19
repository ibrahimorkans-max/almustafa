<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\RoleTemplates;
use App\Domain\Archive\ArchiveService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Gate\VehicleRegister;
use App\Domain\Security\PanicButton;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The only place archived records can be seen: pick a period (by the date each record was closed) and
 * a type, read any record in full, and restore one or every record of the period (reason required,
 * audited). Nothing here can be printed or downloaded, and the rest of the system never shows archived
 * records (there is no "Archived" filter anywhere else).
 */
class ArchiveSearch extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Office Desk';

    protected static ?string $title = 'Office Desk';

    protected static ?string $slug = 'office-desk';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.pages.archive-search';

    public string $from = '';

    public string $to = '';

    public ?string $type = null;

    public string $term = '';

    /** @var list<array{type: string, label: string, id: string, number: string, detail: string, closed_at: string, archived_at: string}> */
    public array $results = [];

    public bool $searched = false;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('office.view');
    }

    public function mount(): void
    {
        $today = CarbonImmutable::now('Asia/Karachi');
        $this->from = $today->subYears(2)->startOfYear()->toDateString();
        $this->to = $today->toDateString();
        $this->retention = app(SettingsService::class)->archiveRetentionMonths();
    }

    public function search(): void
    {
        try {
            $this->results = app(ArchiveService::class)->browse($this->date($this->from), $this->date($this->to), $this->type ?: null, $this->term);
            $this->searched = true;
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    /** Months after closing before a record moves here (one setting for all mills). */
    public int $retention = 24;

    /** The Owner and the Office Coordinator choose when closed records move here (one setting for all mills). */
    public function canSetRetention(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->isOwner() || $user->role?->code === RoleTemplates::OFFICE_COORDINATOR);
    }

    public function saveRetention(): void
    {
        abort_unless($this->canSetRetention(), 403);
        $this->validate(['retention' => ['required', 'integer', 'in:'.implode(',', SettingsService::ARCHIVE_RETENTION_CHOICES)]]);
        $settings = app(SettingsService::class);
        $before = $settings->archiveRetentionMonths();
        $settings->put(SettingsService::ARCHIVE_RETENTION, $this->retention, null);
        app(AuditLogger::class)->record('settings.archive_retention_changed', null, ['months' => $before], ['months' => $this->retention]);
        Notification::make()->title("Saved: closed records move here {$this->retention} months after closing, from the next monthly run.")->success()->send();
    }

    /**
     * Panic button: a device with this account open is lost or stolen. Everything is closed and the
     * account locked (App\Domain\Security\PanicButton); no data is changed. Not for the Owner, whose
     * account has nobody above it to unlock it.
     */
    public function panicAction(): Action
    {
        return Action::make('panic')->label('Panic: device lost or stolen')->color('danger')->icon('heroicon-o-shield-exclamation')
            ->visible(function (): bool {
                $user = Filament::auth()->user();

                return $user instanceof LoginAccount && ! $user->isOwner();
            })
            ->requiresConfirmation()
            ->modalHeading('Lock this account everywhere?')
            ->modalDescription('Use this if a phone or computer signed in to this account is lost or stolen. You are signed out here and on every '
                .'other device at once, phones and the app have their stored data wiped when they next connect, and this account is locked '
                .'until an administrator unlocks it. Nothing in the records is changed.')
            ->modalSubmitActionLabel('Yes, lock everything now')
            ->form([Textarea::make('note')->label('What happened (optional)')->maxLength(500)])
            ->action(function (array $data): void {
                $user = Filament::auth()->user();
                abort_unless($user instanceof LoginAccount && ! $user->isOwner(), 403);
                app(PanicButton::class)->press($user, $data['note'] ?? null);
                Filament::auth()->logout();
                session()->invalidate();
                session()->regenerateToken();
                $this->redirect(Filament::getLoginUrl());
            });
    }

    public function canRestore(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('office.restore');
    }

    public function viewAction(): Action
    {
        return Action::make('view')->label('View')->icon('heroicon-o-eye')->color('gray')->link()
            ->modalHeading(fn (array $arguments) => 'Record')
            ->modalWidth('4xl')->slideOver()
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments) {
                try {
                    $shown = app(ArchiveService::class)->show((string) $arguments['type'], (string) $arguments['id']);
                } catch (BusinessRuleViolation $e) {
                    return view('filament.pages.archive-record', ['error' => $e->getMessage()]);
                }
                $vehicle = $shown['record'] instanceof GateEntry ? app(VehicleRegister::class)->details($shown['record']) : null;

                return view('filament.pages.archive-record', ['shown' => $shown, 'vehicle' => $vehicle]);
            });
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')->color('warning')->icon('heroicon-o-arrow-uturn-up')->link()
            ->visible(fn () => $this->canRestore())
            ->modalDescription('The record returns to the working screens and reports. The reason is kept in the audit log.')
            ->form([Textarea::make('reason')->required()->minLength(10)->maxLength(1000)])
            ->action(function (array $data, array $arguments): void {
                try {
                    app(ArchiveService::class)->restore((string) $arguments['type'], (string) $arguments['id'], $data['reason']);
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('Restored')->success()->send();
                $this->search();
            });
    }

    public function restorePeriodAction(): Action
    {
        return Action::make('restorePeriod')->label('Restore all in this period')->color('warning')->icon('heroicon-o-arrow-uturn-up')
            ->visible(fn () => $this->canRestore() && $this->searched && $this->results !== [])
            ->requiresConfirmation()
            ->modalHeading(fn () => 'Restore '.count($this->results).' record(s)?')
            ->modalDescription(fn () => 'Every '.($this->type ? strtolower(ArchiveService::TYPES[$this->type][3]) : 'record')
                .' closed between '.$this->date($this->from)->format('d M Y').' and '.$this->date($this->to)->format('d M Y')
                .' returns to the working screens and reports. The reason is kept in the audit log.')
            ->form([Textarea::make('reason')->required()->minLength(10)->maxLength(1000)])
            ->action(function (array $data): void {
                try {
                    $count = app(ArchiveService::class)->restorePeriod($this->date($this->from), $this->date($this->to), $this->type ?: null, $data['reason']);
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title($count.' record(s) restored')->success()->send();
                $this->search();
            });
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Karachi') ?: CarbonImmutable::now('Asia/Karachi')->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now('Asia/Karachi')->startOfDay();
        }
    }
}
