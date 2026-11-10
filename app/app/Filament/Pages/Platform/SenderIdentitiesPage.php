<?php

declare(strict_types=1);

namespace App\Filament\Pages\Platform;

use App\Domain\Messaging\Integrations;
use App\Domain\Messaging\SenderIdentities;
use App\Domain\Saas\PlatformCompany;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Usage\ServiceUsage;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Platform → Sender identities (SaaS owner): who each company's and mill's messages come from. The WhatsApp, SMS and
 * e-mail connections are the platform's (Integrations); here each company (its default) or mill is given its
 * provider-approved sender — the WhatsApp number (phone number ID) with its approved business name, the SMS sender,
 * the e-mail address. A mill with none sends nothing on that channel; the platform's own number is used for a mill
 * only when chosen here on purpose. Also: the health of the connections — mills with no sender, failed calls.
 */
class SenderIdentitiesPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Sender identities';

    protected static ?string $title = 'Sender identities & integration health';

    protected static ?string $slug = 'platform/sender-identities';

    protected static ?int $navigationSort = 28;

    protected static string $view = 'filament.pages.platform.sender-identities';

    public string $search = '';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin();
    }

    /** @return list<array<string, mixed>> */
    public function companies(): array
    {
        return app(TenantContext::class)->runAsSystem(function (): array {
            $rows = DB::table('sender_identities')->get();
            $mills = Entity::query()->orderBy('name')->get(['id', 'name', 'code', 'customer_id'])->groupBy('customer_id');
            $term = mb_strtolower(trim($this->search));

            return Customer::query()->whereNotIn('id', PlatformCompany::customerIds())->orderBy('name')->get(['id', 'name'])
                ->filter(fn (Customer $c) => $term === '' || str_contains(mb_strtolower($c->name.' '.($mills[$c->id] ?? collect())->pluck('name')->implode(' ')), $term))
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name,
                    'own' => $rows->where('customer_id', $c->id)->keyBy('channel')->all(),
                    'mills' => ($mills[$c->id] ?? collect())->map(fn (Entity $e) => ['id' => $e->id, 'name' => $e->name.' ('.$e->code.')',
                        'own' => $rows->where('entity_id', $e->id)->keyBy('channel')->all(),
                        'effective' => collect(SenderIdentities::CHANNELS)->keys()->mapWithKeys(fn ($ch) => [$ch => SenderIdentities::assigned($ch, $e->id)])->all(),
                    ])->values()->all()])->values()->all();
        });
    }

    /** @return array{whatsapp: bool, ai: bool, unassigned: int, failed: list<object>} the connections' health */
    public function health(): array
    {
        return app(TenantContext::class)->runAsSystem(fn () => [
            'whatsapp' => filled(Integrations::whatsapp(null)['access_token']),
            'ai' => filled(Integrations::ai(null)['api_key']),
            // Mills whose WhatsApp is switched off here (inactive or waiting): the others send from the one number.
            'unassigned' => DB::table('sender_identities')->where('channel', 'whatsapp')->where('status', '!=', 'approved')->count(),
            'failed' => ServiceUsage::query()->where('success', false)->where('occurred_at', '>=', now()->subDay())
                ->selectRaw('provider, count(*) as n, max(error) as error')->groupBy('provider')->toBase()->get()->all(),
        ]);
    }

    public function senderAction(): Action
    {
        return Action::make('sender')->label('Set')->icon('heroicon-m-pencil-square')->size('xs')->color('gray')
            ->modalHeading(fn (array $arguments) => (SenderIdentities::CHANNELS[$arguments['channel'] ?? ''] ?? '').' sender')
            ->fillForm(function (array $arguments): array {
                $row = app(TenantContext::class)->runAsSystem(fn () => DB::table('sender_identities')->where('channel', $arguments['channel'])
                    ->where(isset($arguments['mill']) ? 'entity_id' : 'customer_id', $arguments['mill'] ?? $arguments['customer'])->first());

                return $row === null ? ['keep' => 'set', 'status' => 'approved', 'language' => 'en', 'provider' => $arguments['channel'] === 'whatsapp' ? 'WhatsApp Cloud (Meta)' : '']
                    : ['keep' => 'set'] + array_diff_key((array) $row, ['credential_ref' => true]) + ['templates' => json_decode((string) ($row->templates ?? ''), true) ?: []];
            })
            ->form(fn (array $arguments) => [
                Select::make('keep')->label(isset($arguments['mill']) ? 'This mill' : 'This company')->live()->required()
                    ->options(['set' => 'Has this sender', 'remove' => isset($arguments['mill']) ? "Uses its company's sender (or none)" : 'No company sender']),
                Grid::make(2)->visible(fn (Get $get) => $get('keep') === 'set')->schema(array_values(array_filter([
                    $arguments['channel'] === 'whatsapp' ? Toggle::make('platform_sender')->label("Send from the platform's one number (the name below goes in each message)")->default(true)->live()->columnSpanFull() : null,
                    TextInput::make('display_name')->label('Company name shown in messages')->required()->maxLength(160),
                    TextInput::make('sender')->label(match ($arguments['channel']) {
                        'email' => 'E-mail address', 'sms' => 'SMS sender ID or number', default => 'WhatsApp number'
                    })
                        ->required(fn (Get $get) => ! $get('platform_sender'))->maxLength(160),
                    $arguments['channel'] === 'whatsapp' ? TextInput::make('phone_number_id')->label('Phone number ID (WhatsApp Manager)')->regex('/^\d{6,30}$/')->hidden(fn (Get $get) => (bool) $get('platform_sender')) : null,
                    $arguments['channel'] === 'whatsapp' ? TextInput::make('business_account_id')->label('Business account ID')->regex('/^\d{6,30}$/')->hidden(fn (Get $get) => (bool) $get('platform_sender')) : null,
                    $arguments['channel'] === 'whatsapp' ? TextInput::make('access_token')->label('Its own access token (only if in another business account)')->password()->revealable(false)
                        ->placeholder('Empty: the platform\'s token')->hidden(fn (Get $get) => (bool) $get('platform_sender')) : null,
                    TextInput::make('provider')->required()->maxLength(60),
                    Select::make('status')->options(SenderIdentities::STATUSES)->required(),
                    TextInput::make('language')->label('Default language')->maxLength(10)->default('en'),
                    $arguments['channel'] === 'whatsapp' ? TagsInput::make('templates')->label('Approved templates (first = default; empty: the platform\'s)')->columnSpanFull() : null,
                ]))),
            ])
            ->action(function (array $data, array $arguments): void {
                try {
                    SenderIdentities::save((string) $arguments['channel'], $arguments['customer'] ?? null, $arguments['mill'] ?? null,
                        $data['keep'] === 'remove' ? null : array_diff_key($data, ['keep' => true]));
                    Notification::make()->success()->title('Sender saved.')->send();
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }
}
