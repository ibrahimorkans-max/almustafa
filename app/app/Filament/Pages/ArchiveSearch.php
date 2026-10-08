<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Archive\ArchiveService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Search archived records and restore one (with a reason, audited). */
class ArchiveSearch extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Archive';

    protected static ?string $title = 'Archive';

    protected static ?string $slug = 'archive';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.pages.archive-search';

    public string $term = '';

    public ?string $type = null;

    /** @var list<array{type: string, label: string, id: string, number: string, detail: string, archived_at: string}> */
    public array $results = [];

    public bool $searched = false;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('archive.search');
    }

    public function search(): void
    {
        try {
            $this->results = app(ArchiveService::class)->search($this->term, $this->type ?: null);
            $this->searched = true;
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        }
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')->color('warning')->icon('heroicon-o-arrow-uturn-up')
            ->visible(fn () => Filament::auth()->user()?->can('archive.restore') ?? false)
            ->modalDescription('The record returns to the working screens. The reason is kept in the audit log.')
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
}
