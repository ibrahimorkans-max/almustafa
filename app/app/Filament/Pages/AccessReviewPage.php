<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Security\AccessReview;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/** Who has which role and permission, last sign-in, where each person is (exact location), and accounts that need a decision. */
class AccessReviewPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $title = 'Access review';

    protected static ?string $slug = 'access-review';

    protected static ?int $navigationSort = 15;

    protected static string $view = 'filament.pages.access-review';

    public string $show = 'attention';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('user.view');
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $rows = app(AccessReview::class)->rows();

        return match ($this->show) {
            'all' => $rows,
            'online' => array_values(array_filter($rows, fn (array $r) => $r['online'] === 'online now')),
            default => array_values(array_filter($rows, fn (array $r) => $r['flags'] !== '')),
        };
    }

    public function dormantDays(): int
    {
        return AccessReview::dormantDays();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Export CSV')->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => response()->streamDownload(
                    fn () => print app(AccessReview::class)->csv(),
                    'access-review-'.now()->timezone('Asia/Karachi')->format('Y-m-d').'.csv',
                    ['Content-Type' => 'text/csv'],
                )),
        ];
    }
}
