<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RoznamchaResource\Pages;

use App\Domain\Accounts\RoznamchaBudgets;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\RoznamchaResource;
use App\Filament\Support\Units;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListRoznamchaPages extends ListRecords
{
    protected static string $resource = RoznamchaResource::class;

    /** Today's budget at the top: the budget, what is paid out, what is left. */
    public function getSubheading(): string|Htmlable|null
    {
        return self::budgetLine(CarbonImmutable::now('Asia/Karachi'), 'Today');
    }

    /** "Today's budget Rs 50,000 · paid out Rs 32,000 · left Rs 18,000", red when over. */
    public static function budgetLine(CarbonImmutable $day, string $label): ?HtmlString
    {
        $d = app(RoznamchaBudgets::class)->day($day);
        if ($d['budget'] === null) {
            return RoznamchaBudgets::canSet() ? new HtmlString(e($label).': no daily budget set (Daily budget, top right).') : null;
        }
        $rs = fn (int $paise) => Units::paiseToRupees($paise);
        $left = (int) $d['left'];
        $color = $d['over'] ? '#dc2626' : ($left < $d['budget'] * 0.1 ? '#d97706' : '#16a34a');

        return new HtmlString(e($label).'\'s budget <strong>'.e($rs($d['budget'])).'</strong>'.($d['own'] ? ' (set for this day)' : '')
            .' · paid out <strong>'.e($rs($d['paid'])).'</strong> · <strong style="color: '.$color.'">'
            .($d['over'] ? 'over by '.e($rs(-$left)) : 'left '.e($rs($left))).'</strong>');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a page'),
            Action::make('budget')->label('Daily budget')->icon('heroicon-o-banknotes')->color('gray')
                ->visible(fn () => RoznamchaBudgets::canSet())
                ->modalHeading('Roznamcha daily budget')
                ->modalDescription('How much cash may be paid out (banam) in a day. A page that takes its day over the budget is posted only with the reason.')
                ->fillForm(fn () => ['what' => 'usual', 'usual' => ($u = app(RoznamchaBudgets::class)->usual()) !== null ? number_format($u / 100, 2, '.', '') : null,
                    'date' => now('Asia/Karachi')->toDateString()])
                ->form([
                    Radio::make('what')->label('Set')->options(['usual' => 'The usual amount for every day', 'day' => 'One day\'s own amount'])->live()->required(),
                    TextInput::make('usual')->label('Usual daily budget (Rs)')->visible(fn ($get) => $get('what') === 'usual')
                        ->regex(Units::DECIMAL_2)->helperText('Empty: no budget.')->prefix('Rs'),
                    DatePicker::make('date')->label('Day')->visible(fn ($get) => $get('what') === 'day')->required(fn ($get) => $get('what') === 'day'),
                    TextInput::make('day_amount')->label('That day\'s budget (Rs)')->visible(fn ($get) => $get('what') === 'day')
                        ->regex(Units::DECIMAL_2)->helperText('Empty: the day goes back to the usual amount.')->prefix('Rs'),
                    TextInput::make('note')->label('Why (optional)')->visible(fn ($get) => $get('what') === 'day')->maxLength(300)
                        ->placeholder('e.g. paddy payments day'),
                ])
                ->action(function (array $data): void {
                    $budgets = app(RoznamchaBudgets::class);
                    $paise = fn ($v) => filled($v) ? Units::rupeesToPaise(str_replace([',', ' '], '', (string) $v)) : null;
                    try {
                        if ($data['what'] === 'usual') {
                            $budgets->setUsual($paise($data['usual'] ?? null));
                        } else {
                            $budgets->setDay(CarbonImmutable::parse((string) $data['date'], 'Asia/Karachi'), $paise($data['day_amount'] ?? null), $data['note'] ?? null);
                        }
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Budget saved.')->send();
                }),
        ];
    }
}
