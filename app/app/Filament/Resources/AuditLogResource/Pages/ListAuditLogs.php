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
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label(__('Verify integrity'))
                ->icon('heroicon-o-shield-check')
                ->visible(fn () => Gate::allows('audit.verify'))
                ->action(function (): void {
                    $entityId = app(TenantContext::class)->entityId();
                    $verifier = app(AuditVerifier::class);
                    $result = $entityId === null ? $verifier->verify() : $verifier->verifyEntity($entityId);
                    app(AuditLogger::class)->record('audit.verified', meta: $result->toArray());

                    $notification = Notification::make()->persistent();
                    if ($result->ok()) {
                        $notification->success()->title(__('Audit log intact'))
                            ->body(__(':rows entries in :chains chain(s) verified.', ['rows' => $result->rows, 'chains' => $result->chains]));
                    } else {
                        $first = $result->failures[0];
                        $notification->danger()->title(__('Audit log verification FAILED'))
                            ->body(__('Chain :chain at #:seq: :error', ['chain' => $first['chain'], 'seq' => $first['seq'], 'error' => $first['error']]));
                    }
                    $notification->send();
                }),
        ];
    }
}
