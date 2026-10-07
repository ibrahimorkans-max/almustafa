<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoginAccountResource\RelationManagers;

use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** IP allowlist per account. Managed by Super Admins only. */
class IpAllowlistRelationManager extends RelationManager
{
    protected static string $relationship = 'ipAllowlist';

    protected static ?string $title = 'IP allowlist';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isSuperAdmin() && $me->hasPermission('user.manage');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('cidr')->label('IP or CIDR')->required()->maxLength(64)
                ->rule(function () {
                    return function (string $attribute, mixed $value, \Closure $fail): void {
                        [$ip, $mask] = array_pad(explode('/', (string) $value, 2), 2, null);
                        $valid = filter_var($ip, FILTER_VALIDATE_IP) !== false
                            && ($mask === null || (ctype_digit($mask) && (int) $mask <= (str_contains((string) $ip, ':') ? 128 : 32)));
                        if (! $valid) {
                            $fail('Enter an IP address or CIDR range, e.g. 203.0.113.10 or 203.0.113.0/24.');
                        }
                    };
                }),
            TextInput::make('note')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cidr')->label('IP / CIDR'),
                TextColumn::make('note'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([
                CreateAction::make()->mutateFormDataUsing(fn (array $data) => $data + [
                    'entity_id' => $this->getOwnerRecord()->getAttribute('entity_id'),
                ]),
            ])
            ->actions([DeleteAction::make()]);
    }
}
