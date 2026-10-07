<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate\ScaleResource\RelationManagers;

use App\Models\Gate\ScaleCertificate;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Stamping (Legal Metrology) and calibration certificates. Never deleted; renewals add rows. */
class CertificatesRelationManager extends RelationManager
{
    protected static string $relationship = 'certificates';

    protected static ?string $title = 'Certificates';

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Select::make('type')->options([ScaleCertificate::TYPE_STAMPING => 'Stamping (Legal Metrology)', ScaleCertificate::TYPE_CALIBRATION => 'Calibration'])->required(),
            TextInput::make('certificate_no')->required()->maxLength(60),
            TextInput::make('issued_by')->maxLength(160),
            DatePicker::make('issued_on')->required(),
            DatePicker::make('valid_until')->required()->afterOrEqual('issued_on'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('certificate_no'),
                TextColumn::make('issued_by')->placeholder('—'),
                TextColumn::make('issued_on')->date(),
                TextColumn::make('valid_until')->date()
                    ->color(fn (ScaleCertificate $r) => $r->daysLeft() < 0 ? 'danger' : ($r->daysLeft() <= 30 ? 'warning' : 'success'))
                    ->description(fn (ScaleCertificate $r) => $r->daysLeft() < 0 ? 'expired' : $r->daysLeft().' days left'),
            ])
            ->defaultSort('valid_until', 'desc')
            ->headerActions([
                CreateAction::make()->visible(fn () => Filament::auth()->user()?->can('master.manage') ?? false)
                    ->mutateFormDataUsing(fn (array $data) => $data + ['entity_id' => $this->getOwnerRecord()->getAttribute('entity_id')]),
            ]);
    }
}
