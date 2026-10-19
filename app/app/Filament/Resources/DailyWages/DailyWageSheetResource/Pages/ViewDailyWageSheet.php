<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyWages\DailyWageSheetResource\Pages;

use App\Domain\DailyWages\DailyWages;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\DailyWages\DailyWageSheetResource;
use App\Filament\Support\PrintButton;
use App\Models\DailyWages\DailyWageSheet;
use App\Support\Values\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDailyWageSheet extends ViewRecord
{
    protected static string $resource = DailyWageSheetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PrintButton::header('daily_wage', fn (DailyWageSheet $s) => $s->status !== DailyWageSheet::CANCELLED),
            DailyWageSheetResource::postAction(Action::class),
            DailyWageSheetResource::payAction(Action::class),
            Action::make('cancel')->label('Cancel')->color('danger')->icon('heroicon-o-x-mark')
                ->visible(fn (DailyWageSheet $record) => $record->status === DailyWageSheet::DRAFT)
                ->form([Textarea::make('reason')->required()->minLength(3)->maxLength(500)])
                ->action(function (DailyWageSheet $record, array $data): void {
                    try {
                        app(DailyWages::class)->cancel($record, (string) $data['reason']);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Cancelled.')->send();
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $rs = fn (?int $p) => $p ? Money::paise($p)->format() : '—';

        return $infolist->schema([
            Section::make()->columns(3)->schema([
                TextEntry::make('number'),
                TextEntry::make('work_date')->label('Work date')->date('d M Y'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => DailyWageSheet::STATUSES[$state] ?? $state),
                TextEntry::make('who')->label('Group / worker')->state(fn (DailyWageSheet $s) => $s->who()),
                TextEntry::make('group.leader_phone')->label('Leader\'s phone')->placeholder('—'),
                TextEntry::make('category.name')->label('Work'),
                TextEntry::make('workers')->label('Workers'),
                TextEntry::make('quantity')->label('Quantity')->formatStateUsing(fn ($state, DailyWageSheet $s) => rtrim(rtrim((string) $state, '0'), '.').' '.$s->unit),
                TextEntry::make('rate_paise')->label('Rate')->formatStateUsing(fn (int $state) => $rs($state)),
                TextEntry::make('gross_paise')->label('Wages')->formatStateUsing(fn (int $state) => $rs($state)),
                TextEntry::make('extra_paise')->label('Extra')->formatStateUsing(fn (int $state) => $rs($state)),
                TextEntry::make('deduction_paise')->label('Less')->formatStateUsing(fn (int $state) => $rs($state)),
                TextEntry::make('net_paise')->label('To pay')->weight('bold')->formatStateUsing(fn (int $state) => $rs($state)),
                TextEntry::make('paidFrom.name')->label('Paid from')->placeholder('—'),
                TextEntry::make('paid_at')->label('Paid at')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('explain')->label('Worked out as')->columnSpanFull()->state(fn (DailyWageSheet $s) => DailyWages::explain($s)),
                TextEntry::make('worker_names')->label('Workers\' names')->columnSpanFull()->placeholder('—'),
                TextEntry::make('description')->label('What was done')->columnSpanFull()->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Cancelled because')->columnSpanFull()->visible(fn (DailyWageSheet $s) => $s->cancel_reason !== null),
            ]),
        ]);
    }
}
