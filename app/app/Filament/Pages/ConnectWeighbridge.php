<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Gate\LiveWeight;
use App\Filament\Resources\DeviceResource;
use App\Filament\Resources\Gate\ScaleResource;
use App\Filament\Resources\LoginAccountResource;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Step-by-step: connecting a weighbridge indicator to the ERP through the gate agent on the
 * weighbridge PC, with each weighbridge's connection state (signal, last reading) at the top.
 * The full guide is docs/connect-weighbridge.md.
 */
class ConnectWeighbridge extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Connect a weighbridge';

    protected static ?string $title = 'Connect a weighbridge';

    protected static ?string $slug = 'connect-weighbridge';

    protected static ?int $navigationSort = 31;

    protected static string $view = 'filament.pages.connect-weighbridge';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('master.manage');
    }

    /** @return list<array{scale_id: string, code: string, name: string, kg: float|null, stable: bool, live: bool, age_seconds: int|null}> */
    public function scales(): array
    {
        return app(LiveWeight::class)->all();
    }

    public function scalesUrl(): string
    {
        return ScaleResource::getUrl();
    }

    public function usersUrl(): string
    {
        return LoginAccountResource::getUrl();
    }

    public function devicesUrl(): string
    {
        return DeviceResource::getUrl();
    }

    public function liveUrl(): string
    {
        return LiveWeightPage::getUrl();
    }

    public function serverUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
