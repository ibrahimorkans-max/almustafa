<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement;

use App\Domain\Procurement\RequirementService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Procurement\PaddyRequirementResource\Pages;
use App\Filament\Support\ProductSelect;
use App\Filament\Support\Units;
use App\Models\Masters\Party;
use App\Models\Procurement\PaddyRequirement;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaddyRequirementResource extends Resource
{
    use \App\Filament\Concerns\TranslatesResourceLabels;

    protected static ?string $model = PaddyRequirement::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Procurement';

    protected static ?string $navigationLabel = 'Paddy requirements';

    protected static ?string $slug = 'requirements';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            ProductSelect::make('product_id', ['paddy'])->label(__('Paddy'))->required(),
            TextInput::make('quantity_maund')->label(__('Quantity (maund)'))->required()->regex(Units::DECIMAL_3),
            TextInput::make('target_rate_rupees')->label(__('Target rate (Rs per maund)'))->regex(Units::DECIMAL_2),
            TextInput::make('moisture_max_percent')->label(__('Max moisture (%)'))->default('17')->regex(Units::DECIMAL_2),
            DatePicker::make('delivery_from'),
            DatePicker::make('delivery_to')->afterOrEqual('delivery_from'),
            Textarea::make('notes')->columnSpanFull()->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('product.name')->label(__('Paddy')),
                TextColumn::make('quantity_kg')->label(__('Qty (maund)'))->formatStateUsing(fn (string $state) => Units::kgToMaund($state))->alignEnd(),
                TextColumn::make('target_rate_paise_per_maund')->label(__('Target / maund'))->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state))->alignEnd(),
                TextColumn::make('recipients_count')->label(__('Sent to'))->counts('recipients'),
                TextColumn::make('quotes_count')->label(__('Quotes'))->counts('quotes'),
                TextColumn::make('status')->badge()->color(fn (string $state) => ['open' => 'success', 'closed' => 'gray'][$state] ?? 'warning'),
                TextColumn::make('delivery_to')->date()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('broadcast')->icon('heroicon-o-megaphone')
                    ->visible(fn (PaddyRequirement $r) => $r->status !== 'closed' && (Filament::auth()->user()?->can('requirement.broadcast') ?? false))
                    ->form([
                        Select::make('party_ids')->label(__('Send to'))->multiple()->required()->searchable()
                            ->options(fn () => Party::query()->whereRaw('(is_broker OR is_farmer OR is_vendor)')->orderBy('name')->pluck('name', 'id')),
                    ])
                    ->action(function (PaddyRequirement $r, array $data): void {
                        try {
                            $n = app(RequirementService::class)->broadcast($r, $data['party_ids']);
                            Notification::make()->title(__('Sent to :n new recipient(s)', ['n' => $n]))->success()->send();
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('close')->color('gray')->requiresConfirmation()
                    ->visible(fn (PaddyRequirement $r) => $r->status === 'open' && (Filament::auth()->user()?->can('requirement.broadcast') ?? false))
                    ->action(fn (PaddyRequirement $r) => app(RequirementService::class)->close($r)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaddyRequirements::route('/'),
            'create' => Pages\CreatePaddyRequirement::route('/create'),
        ];
    }
}
