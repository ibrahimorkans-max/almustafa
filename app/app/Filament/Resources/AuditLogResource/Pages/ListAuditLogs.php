<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\AuditVerifier;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\AuditLogResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label('Verify integrity')
                ->icon('heroicon-o-shield-check')
                ->visible(fn () => Gate::allows('audit.verify'))
                ->action(function (): void {
                    $entityId = app(TenantContext::class)->entityId();
                    $verifier = app(AuditVerifier::class);
                    $result = $entityId === null ? $verifier->verify() : $verifier->verifyEntity($entityId);
                    app(AuditLogger::class)->record('audit.verified', meta: $result->toArray());

                    $notification = Notification::make()->persistent();
                    if ($result->ok()) {
                        $notification->success()->title('Audit log intact')
                            ->body("{$result->rows} entries in {$result->chains} chain(s) verified.");
                    } else {
                        $first = $result->failures[0];
                        $notification->danger()->title('Audit log verification FAILED')
                            ->body("Chain {$first['chain']} at #{$first['seq']}: {$first['error']}");
                    }
                    $notification->send();
                }),
        ];
    }
}
