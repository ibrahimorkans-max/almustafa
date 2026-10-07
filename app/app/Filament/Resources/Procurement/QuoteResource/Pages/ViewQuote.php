<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\QuoteResource\Pages;

use App\Domain\Procurement\QuoteService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Procurement\QuoteResource;
use App\Filament\Resources\Procurement\SaudaResource;
use App\Filament\Support\Units;
use App\Models\Procurement\Quote;
use App\Models\Procurement\Sauda;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * The back office acts for the mill and also records what the seller/broker says by phone,
 * so actions name whose move they record.
 */
class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        $open = fn (Quote $r) => $r->isOpen() && (Filament::auth()->user()?->can('quote.manage') ?? false);
        $other = fn (Quote $r) => $r->last_offer_by === Quote::BY_MILL ? Quote::BY_SELLER : Quote::BY_MILL;
        $counterForm = [
            TextInput::make('quantity_quintal')->label('Quantity (quintal)')->required()->regex(Units::DECIMAL_3),
            TextInput::make('rate_rupees')->label('Rate (₹ per quintal)')->required()->regex(Units::DECIMAL_2),
            Textarea::make('note')->maxLength(1000),
        ];

        return [
            Action::make('counter')->label(fn (Quote $r) => $other($r) === Quote::BY_MILL ? 'Counter-offer (mill)' : 'Record seller counter-offer')
                ->visible($open)
                ->fillForm(fn (Quote $r) => ['quantity_quintal' => Units::kgToQuintal($r->quantity_kg), 'rate_rupees' => bcdiv((string) $r->rate_paise_per_quintal, '100', 2)])
                ->form($counterForm)
                ->action(fn (Quote $r, array $data) => $this->run(fn (QuoteService $s) => $s->counter(
                    $r, Units::quintalToKg((string) $data['quantity_quintal']), Units::rupeesToPaise((string) $data['rate_rupees']), $other($r), $data['note'] ?? null,
                ), 'Counter-offer recorded')),
            Action::make('accept')->color('success')->icon('heroicon-o-check')
                ->label(fn (Quote $r) => $other($r) === Quote::BY_MILL ? 'Accept seller\'s offer' : 'Record seller\'s acceptance')
                ->visible($open)
                ->requiresConfirmation()
                ->modalDescription(fn (Quote $r) => 'Creates a sauda for '.Units::kgToQuintal($r->quantity_kg).' qtl at '.Units::paiseToRupees($r->rate_paise_per_quintal).'/qtl and sends it for approval.')
                ->action(function (Quote $r) use ($other): void {
                    $sauda = null;
                    $this->run(function (QuoteService $s) use ($r, $other, &$sauda) {
                        $sauda = $s->accept($r, $other($r));

                        return $r->fresh();
                    }, 'Sauda created and sent for approval');
                    if ($sauda instanceof Sauda) {
                        $this->redirect(SaudaResource::getUrl('view', ['record' => $sauda]));
                    }
                }),
            Action::make('reject')->color('danger')->visible($open)
                ->form([Textarea::make('note')->maxLength(1000)])
                ->action(fn (Quote $r, array $data) => $this->run(fn (QuoteService $s) => $s->reject($r, $other($r), $data['note'] ?? null), 'Quote rejected')),
        ];
    }

    /** @param  callable(QuoteService): (Quote|null)  $callback */
    private function run(callable $callback, string $success): void
    {
        try {
            $result = $callback(app(QuoteService::class));
            if ($result instanceof Quote) {
                $this->record = $result;
            }
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($success)->success()->send();
    }
}
