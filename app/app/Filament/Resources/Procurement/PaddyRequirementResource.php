<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement;

use App\Domain\Procurement\RequirementService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Procurement\PaddyRequirementResource\Pages;
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
use Illuminate\Database\Eloquent\Builder;

class PaddyRequirementResource extends Resource
{
    protected static ?string $model = PaddyRequirement::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Procurement';

    protected static ?string $navigationLabel = 'Paddy requirements';

    protected static ?string $slug = 'requirements';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('product_id')->label('Paddy')->required()->preload()
                ->relationship('product', 'name', fn (Builder $query) => $query->where('category', 'paddy')),
            TextInput::make('quantity_quintal')->label('Quantity (quintal)')->required()->regex(Units::DECIMAL_3),
            TextInput::make('target_rate_rupees')->label('Target rate (₹ per quintal)')->regex(Units::DECIMAL_2),
            TextInput::make('moisture_max_percent')->label('Max moisture (%)')->default('17')->regex(Units::DECIMAL_2),
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
                TextColumn::make('product.name')->label('Paddy'),
                TextColumn::make('quantity_kg')->label('Qty (qtl)')->formatStateUsing(fn (string $state) => Units::kgToQuintal($state))->alignEnd(),
                TextColumn::make('target_rate_paise_per_quintal')->label('Target / qtl')->formatStateUsing(fn (?int $state) => Units::paiseToRupees($state))->alignEnd(),
                TextColumn::make('recipients_count')->label('Sent to')->counts('recipients'),
                TextColumn::make('quotes_count')->label('Quotes')->counts('quotes'),
                TextColumn::make('status')->badge()->color(fn (string $state) => ['open' => 'success', 'closed' => 'gray'][$state] ?? 'warning'),
                TextColumn::make('delivery_to')->date()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('broadcast')->icon('heroicon-o-megaphone')
                    ->visible(fn (PaddyRequirement $r) => $r->status !== 'closed' && (Filament::auth()->user()?->can('requirement.broadcast') ?? false))
                    ->form([
                        Select::make('party_ids')->label('Send to')->multiple()->required()->searchable()
                            ->options(fn () => Party::query()->whereRaw('(is_broker OR is_farmer OR is_vendor)')->orderBy('name')->pluck('name', 'id')),
                    ])
                    ->action(function (PaddyRequirement $r, array $data): void {
                        try {
                            $n = app(RequirementService::class)->broadcast($r, $data['party_ids']);
                            Notification::make()->title("Sent to {$n} new recipient(s)")->success()->send();
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
