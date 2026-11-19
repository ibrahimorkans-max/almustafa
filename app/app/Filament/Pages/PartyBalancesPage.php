<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reports\Balances;
use App\Filament\Concerns\TranslatesLabels;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Reports → Party balances: every party's balance from the books — who owes the mill and whom the mill owes, the
 * last money in and out, unpaid quick bills — with a link to each party's statement (payment records).
 */
class PartyBalancesPage extends Page
{
    use TranslatesLabels;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Party balances';

    protected static ?string $title = 'Party balances';

    protected static ?string $slug = 'party-balances';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.party-balances';

    #[Url]
    public string $search = '';

    #[Url]
    public string $show = 'open';

    #[Url(as: 'as_of')]
    public string $asOf = '';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->hasPermission('ledger.view');
    }

    public function mount(): void
    {
        $this->asOf = $this->asOf !== '' ? $this->asOf : now('Asia/Karachi')->toDateString();
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->asOf) ? $this->asOf : null;

        return app(Balances::class)->parties(trim($this->search) ?: null, in_array($this->show, ['all', 'open', 'owe_us', 'we_owe'], true) ? $this->show : 'open', $asOf);
    }
}
