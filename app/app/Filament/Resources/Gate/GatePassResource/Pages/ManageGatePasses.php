<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\GatePassResource\Pages;

use App\Domain\Documents\PrintDelivery;
use App\Domain\Gate\GatePasses;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GatePassResource;
use App\Filament\Support\WhatsAppFileButton;
use App\Models\Gate\GatePass;
use App\Models\LoginAccount;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Actions\Action;
use Livewire\Component;

class ManageGatePasses extends ManageRecords
{
    protected static string $resource = GatePassResource::class;

    protected ?string $subheading = 'What leaves on a vehicle: party, vehicle number, bags and weight per bag. Each pass has a serial number and a verification code the gate can check by scanning it.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New gate pass')->icon('heroicon-o-plus')->modalHeading('New gate pass')
                ->modalSubmitActionLabel('Issue and print')->createAnother(false)
                ->using(function (array $data, CreateAction $action): GatePass {
                    try {
                        return app(GatePasses::class)->issue($data);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        $action->halt();
                        throw $e;   // not reached: halt() stops here
                    }
                })
                ->successNotificationTitle(fn (GatePass $record) => 'Gate pass '.$record->serial.' issued: '.number_format($record->bag_count).' bags, '.number_format((float) $record->total_kg, 2).' kg.')
                ->after(fn (GatePass $record, Component $livewire) => PrintDelivery::toPrinter($livewire, app(GatePasses::class)->pdf($record), self::fileName($record))),
        ];
    }

    /** @return list<Action> */
    public static function rowActions(): array
    {
        return [
            Action::make('print')->label('Print')->icon('heroicon-o-printer')->color('gray')
                ->action(fn (GatePass $record, Component $livewire) => PrintDelivery::toPrinter($livewire, app(GatePasses::class)->pdf($record), self::fileName($record))),
            Action::make('pdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(fn (GatePass $record) => response()->streamDownload(fn () => print app(GatePasses::class)->pdf($record), self::fileName($record))),
            WhatsAppFileButton::row('whatsapp', fn (GatePass $record) => ['party' => $record->party, 'name' => $record->party_name, 'document' => 'gate pass',
                'number' => $record->serial, 'file' => self::fileName($record), 'pdf' => fn () => app(GatePasses::class)->pdf($record)],
                fn (?GatePass $record) => $record === null || $record->cancellation === null)->label('WhatsApp'),
            Action::make('cancel')->label('Cancel')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(function (GatePass $record): bool {
                    $me = Filament::auth()->user();

                    return $record->cancellation === null && $me instanceof LoginAccount && $me->hasPermission('gatepass.issue');
                })
                ->modalHeading(fn (GatePass $record) => 'Cancel gate pass '.$record->serial)
                ->modalDescription('The pass stays on the list marked cancelled, and the check page says it is not valid. Issue a new one if the vehicle still needs a pass.')
                ->form([Textarea::make('reason')->label('Why is it cancelled?')->required()->minLength(5)->maxLength(300)])
                ->action(function (GatePass $record, array $data): void {
                    try {
                        app(GatePasses::class)->cancel($record, (string) $data['reason']);
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('Gate pass '.$record->serial.' cancelled.')->send();
                }),
        ];
    }

    public static function fileName(GatePass $pass): string
    {
        return 'gate-pass-'.str_replace('/', '-', $pass->serial).'.pdf';
    }
}
