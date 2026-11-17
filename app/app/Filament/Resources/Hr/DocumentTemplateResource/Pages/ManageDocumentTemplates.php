<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\DocumentTemplateResource\Pages;

use App\Domain\Documents\Engine\DocumentTypes;
use App\Domain\Documents\Engine\TemplateRepository;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\DocumentTemplateResource;
use App\Models\Documents\DocumentTemplate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentTemplates extends ManageRecords
{
    use \App\Filament\Concerns\TranslatesLabels;

    protected static string $resource = DocumentTemplateResource::class;

    public function mount(): void
    {
        parent::mount();
        // The four standard templates of every document exist from the first visit.
        foreach (array_keys(DocumentTypes::TYPES) as $type) {
            app(TemplateRepository::class)->installStandard($type);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('New template'))->icon('heroicon-o-plus')
                ->form([
                    Select::make('doc_type')->label(__('Document'))->options(\App\Support\Locales::options(DocumentTypes::options()))->required(),
                    TextInput::make('name')->required()->maxLength(80),
                    Select::make('layout')->options(\App\Support\Locales::options(DocumentTemplate::LAYOUTS))->required()->default('classic'),
                    ...DocumentTemplateResource::settingsForm(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(TemplateRepository::class)->create($data['doc_type'], $data['name'], $data['layout'], DocumentTemplateResource::settings($data), (string) $data['note']);
                        Notification::make()->title(__('Template created.'))->success()->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
