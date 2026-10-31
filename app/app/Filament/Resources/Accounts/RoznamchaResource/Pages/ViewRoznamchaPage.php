<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RoznamchaResource\Pages;

use App\Domain\Accounts\Roznamcha;
use App\Domain\Accounts\RoznamchaBudgets;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\RoznamchaResource;
use App\Models\Accounts\RoznamchaPage;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewRoznamchaPage extends ViewRecord
{
    protected static string $resource = RoznamchaResource::class;

    /** The page's day against the roznamcha budget. */
    public function getSubheading(): string|Htmlable|null
    {
        /** @var RoznamchaPage $page */
        $page = $this->record;
        $day = CarbonImmutable::parse($page->entry_date->toDateString(), 'Asia/Karachi');

        return ListRoznamchaPages::budgetLine($day, $day->format('d M Y'));
    }

    protected function getHeaderActions(): array
    {
        $can = fn () => Filament::auth()->user()?->can('ledger.post') ?? false;

        return [
            Action::make('lines')->label('Correct entries')->icon('heroicon-o-pencil-square')
                ->visible(fn (RoznamchaPage $record) => $record->status === 'draft' && $can())
                ->modalWidth('7xl')
                ->modalDescription('Check each entry against the scan: the party or account, and cash received or paid.')
                ->fillForm(fn (RoznamchaPage $record) => ['lines' => RoznamchaResource::linesForForm($record)])
                ->form(RoznamchaResource::lineFields())
                ->action(fn (RoznamchaPage $record, array $data) => $this->run(fn (Roznamcha $r) => $r->saveLines($record, array_values((array) ($data['lines'] ?? []))), 'Entries saved')),
            Action::make('verify')->label('Mark checked')->icon('heroicon-o-check-badge')->color('info')
                ->visible(fn (RoznamchaPage $record) => $record->status === 'draft' && $can())
                ->requiresConfirmation()->modalDescription('You checked every entry against the scanned page.')
                ->action(fn (RoznamchaPage $record) => $this->run(fn (Roznamcha $r) => $r->verify($record), 'Checked')),
            Action::make('post')->label('Post to the books')->icon('heroicon-o-check')->color('success')
                ->visible(fn (RoznamchaPage $record) => $record->status === 'verified' && $can())
                ->requiresConfirmation()->modalDescription(fn (RoznamchaPage $record) => 'Each entry becomes a cash receipt or payment voucher. A posted page cannot be changed.'
                    .(self::over($record) ? ' This day is OVER its roznamcha budget: give the reason.' : ''))
                ->form(fn (RoznamchaPage $record) => self::over($record) ? [Textarea::make('reason')->label('Why is more paid out than the budget?')->required()->minLength(5)->maxLength(300)] : [])
                ->action(fn (RoznamchaPage $record, array $data) => $this->run(fn (Roznamcha $r) => $r->post($record, $data['reason'] ?? null), 'Posted')),
        ];
    }

    private static function over(RoznamchaPage $page): bool
    {
        return app(RoznamchaBudgets::class)->day(CarbonImmutable::parse($page->entry_date->toDateString(), 'Asia/Karachi'))['over'];
    }

    /** @param  callable(Roznamcha): RoznamchaPage  $callback */
    private function run(callable $callback, string $success): void
    {
        try {
            $this->record = $callback(app(Roznamcha::class));
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $this->record->refresh();
        Notification::make()->title($success)->success()->send();
    }
}
