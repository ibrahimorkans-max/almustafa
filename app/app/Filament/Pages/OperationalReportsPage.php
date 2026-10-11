<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Reports\OperationalReports;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** Registers and summaries with CSV export (exports are audited). */
class OperationalReportsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Reports';

    protected static ?string $slug = 'reports';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.pages.operational-reports';

    #[Url]
    public string $report = 'gate_register';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('report.view');
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now('Asia/Karachi')->startOfMonth()->toDateString();
        $this->to = $this->to ?: now('Asia/Karachi')->toDateString();
        if (! isset(OperationalReports::REPORTS[$this->report])) {
            $this->report = 'gate_register';
        }
    }

    /** @return array{0: list<string>, 1: list<list<string|int|float|null>>} */
    public function getData(): array
    {
        return app(OperationalReports::class)->run($this->report, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Export CSV')->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    $csv = app(OperationalReports::class)->csv($this->report, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));

                    return response()->streamDownload(fn () => print $csv, "{$this->report}-{$this->from}-{$this->to}.csv", ['Content-Type' => 'text/csv']);
                }),
        ];
    }
}
