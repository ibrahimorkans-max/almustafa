<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Domain\Access\RoleTwoFactor;
use App\Domain\Approvals\Actions\ChangeRolePermissions;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Concerns\TranslatesLabels;
use App\Filament\Resources\RoleResource;
use App\Filament\Support\ApprovalNotice;
use App\Models\LoginAccount;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewRole extends ViewRecord
{
    use TranslatesLabels;

    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->twoFactorAction(),
            Action::make('requestChange')
                ->label(__('Request permission change'))
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (Role $record) => Gate::allows('requestChange', $record))
                ->modalDescription(__('The change takes effect only after a different person approves it (maker-checker).'))
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
                        Notification::make()->title(__('Request not submitted'))->body(implode(' ', $e->validator->errors()->all()))->danger()->send();

                        return;
                    }

                    ApprovalNotice::send($request, 'Request submitted for approval');
                }),
        ];
    }

    /** Owner / Super Admin: everyone in this role must (or need not) set up their own authenticator app. */
    private function twoFactorAction(): Action
    {
        $me = fn () => Filament::auth()->user();

        return Action::make('twoFactor')
            ->label(fn (Role $record) => $record->mfa_required ? __('Stop requiring 2FA') : __('Require 2FA for this role'))
            ->icon(fn (Role $record) => $record->mfa_required ? 'heroicon-o-lock-open' : 'heroicon-o-shield-check')
            ->color(fn (Role $record) => $record->mfa_required ? 'gray' : 'success')
            ->visible(fn (Role $record) => $me() instanceof LoginAccount && RoleTwoFactor::canChange($me(), $record))
            ->requiresConfirmation()
            ->modalHeading(fn (Role $record) => $record->mfa_required ? __('Stop requiring 2FA') : __('Require 2FA for this role'))
            ->modalDescription(fn (Role $record) => $record->mfa_required
                ? __('People in this role may then sign in with a password alone, and a new phone or network will need someone\'s approval or an e-mail code.')
                : __('Everyone in this role sets up an authenticator app (Google or Microsoft Authenticator) at their next screen. With it they prove a new network, a new phone or a forgotten password themselves, without anyone\'s approval. Not set up yet: :count.',
                    ['count' => RoleTwoFactor::notSetUp($record)]))
            ->form(fn (Role $record) => $record->mfa_required ? [Textarea::make('reason')->label(__('Why is it no longer needed?'))->required()->minLength(5)->maxLength(500)] : [])
            ->action(function (Role $record, array $data) use ($me): void {
                $on = ! $record->mfa_required;
                try {
                    app(RoleTwoFactor::class)->set($me(), $record, $on, $data['reason'] ?? null);
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->title(__($e->getMessage()))->danger()->send();

                    return;
                }
                Notification::make()->success()->title($on ? __('2FA is now required for this role.') : __('2FA is no longer required for this role.'))->send();
                $this->refreshFormData(['mfa_required']);
            });
    }
}
