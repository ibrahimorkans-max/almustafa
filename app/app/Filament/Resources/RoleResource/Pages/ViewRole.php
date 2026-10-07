<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Domain\Approvals\Actions\ChangeRolePermissions;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Filament\Resources\ApprovalRequestResource;
use App\Filament\Resources\RoleResource;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestChange')
                ->label('Request permission change')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (Role $record) => Gate::allows('requestChange', $record))
                ->modalDescription('The change takes effect only after a different person approves it (maker-checker).')
                ->fillForm(fn (Role $record) => collect(RoleResource::permissionOptionsByModule())
                    ->mapWithKeys(fn (array $options, string $module) => [
                        'module_'.$module => array_values(array_intersect(array_keys($options), $record->permissionCodes())),
                    ])->all())
                ->form(fn () => [
                    ...collect(RoleResource::permissionOptionsByModule())->map(fn (array $options, string $module) => Fieldset::make($module)->schema([
                        CheckboxList::make('module_'.$module)->hiddenLabel()->options($options)->columns(1)->bulkToggleable(),
                    ]))->values()->all(),
                    Textarea::make('reason')->required()->maxLength(1000),
                ])
                ->action(function (Role $record, array $data): void {
                    $permissions = collect($data)->filter(fn ($v, $k) => str_starts_with((string) $k, 'module_'))->flatten()->all();

                    try {
                        $request = app(ApprovalService::class)->submit(
                            ChangeRolePermissions::KEY,
                            ['role_id' => $record->id, 'permissions' => $permissions],
                            "Change permissions of role {$record->name}",
                            $data['reason'],
                        );
                    } catch (ApprovalDenied $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    } catch (ValidationException $e) {
                        Notification::make()->title('Request not submitted')->body(implode(' ', $e->validator->errors()->all()))->danger()->send();

                        return;
                    }

                    Notification::make()->title('Request submitted for approval')->success()
                        ->actions([\Filament\Notifications\Actions\Action::make('open')->url(ApprovalRequestResource::getUrl('view', ['record' => $request]))])
                        ->send();
                }),
        ];
    }
}
