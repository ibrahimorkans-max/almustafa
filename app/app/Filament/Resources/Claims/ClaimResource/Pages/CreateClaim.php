<?php

declare(strict_types=1);

namespace App\Filament\Resources\Claims\ClaimResource\Pages;

use App\Domain\Claims\ClaimService;
use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Claims\ClaimResource;
use App\Filament\Support\Units;
use App\Models\Accounts\PurchaseBill;
use App\Models\Claims\Claim;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreateClaim extends CreateRecord
{
    protected static string $resource = ClaimResource::class;

    protected static ?string $title = 'Raise claim';

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $document = $data['document_type'] === Claim::PURCHASE
            ? PurchaseBill::query()->findOrFail($data['document_id'])
            : SalesBill::query()->findOrFail($data['document_id']);

        try {
            return app(ClaimService::class)->raise(
                $document, (string) $data['type'], (string) $data['description'], Units::rupeesToPaise((string) $data['amount']),
                self::store((array) ($data['evidence'] ?? [])),
                filled($data['transporter_party_id'] ?? null) ? Party::query()->findOrFail($data['transporter_party_id']) : null,
            );
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages(['data.amount' => $e->getMessage()]);
        }
    }

    /**
     * Uploads go into the content-addressed store; the claim keeps their hashes.
     *
     * @param  array<int|string, mixed>  $files
     * @return list<string>
     */
    public static function store(array $files): array
    {
        $hashes = [];
        foreach ($files as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $hashes[] = app(FileStore::class)->put((string) $file->get())->sha256;
            }
        }

        return $hashes;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
