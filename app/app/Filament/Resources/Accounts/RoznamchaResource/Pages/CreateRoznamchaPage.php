<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RoznamchaResource\Pages;

use App\Domain\Accounts\NetworkScanner;
use App\Domain\Accounts\Roznamcha;
use App\Domain\Accounts\RoznamchaCapture;
use App\Domain\Files\FileStore;
use App\Domain\Security\EntryLocation;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Resources\Accounts\RoznamchaResource;
use App\Models\Accounts\LedgerAccount;
use App\Models\StoredFile;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Adding a roznamcha page, each input as it works best:
 *  - mobile camera: the phone's camera directly, or a QR code / link that opens it on a phone (RoznamchaCapture);
 *  - scanner: "Scan now" reads the network scanner (NetworkScanner), or a file the scanner saved;
 *  - PDF / image: the file;
 *  - typed by hand: the entries on the form itself.
 */
class CreateRoznamchaPage extends CreateRecord
{
    protected static string $resource = RoznamchaResource::class;

    protected static ?string $title = 'Add a roznamcha page';

    /** A QR code and link for a phone's camera. */
    public function startCapture(): void
    {
        $this->data['capture_token'] = app(RoznamchaCapture::class)->start((string) Filament::auth()->id());
        $this->data['capture_file_id'] = null;
    }

    /** Called every few seconds while the QR code shows: the phone's photo, once it arrived. */
    public function checkCapture(): void
    {
        $token = $this->data['capture_token'] ?? null;
        $file = is_string($token) ? app(RoznamchaCapture::class)->received($token, (string) Filament::auth()->id()) : null;
        if ($file !== null && ($this->data['capture_file_id'] ?? null) === null) {
            $this->data['capture_file_id'] = $file;
            Notification::make()->success()->title('Photo received from the phone.')->send();
        }
    }

    /** Reads the page on the network scanner's glass. */
    public function scanNow(): void
    {
        try {
            $address = NetworkScanner::address(app(TenantContext::class)->entityId())
                ?? throw new BusinessRuleViolation('No scanner is set: Administration → Integrations → Scanner.');
            $bytes = app(NetworkScanner::class)->scan($address);
            $this->data['scanned_file_id'] = app(FileStore::class)->put($bytes)->id;
            Notification::make()->success()->title('Page scanned.')->send();
        } catch (BusinessRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        EntryLocation::hold($data);
        $source = (string) $data['source'];
        $scan = $data['scan'] ?? null;
        if (is_array($scan)) {
            $scan = reset($scan) ?: null;
        }
        $bytes = $scan instanceof TemporaryUploadedFile ? (string) $scan->get() : null;
        // A photo from the phone or a page from the scanner, already kept for the mill.
        $kept = match ($source) {
            'camera' => $data['capture_file_id'] ?? null,
            'scanner' => $data['scanned_file_id'] ?? null,
            default => null,
        };
        if ($bytes === null && is_string($kept) && $kept !== '') {
            $file = StoredFile::query()->find($kept);
            $bytes = $file !== null ? (string) Storage::disk('local')->get($file->path) : null;
        }
        try {
            return DB::transaction(function () use ($data, $source, $bytes): Model {
                $roznamcha = app(Roznamcha::class);
                $page = $roznamcha->record(
                    CarbonImmutable::parse((string) $data['entry_date'], 'Asia/Karachi'),
                    $source,
                    $source === 'manual' ? null : $bytes,
                    $data['note'] ?? null,
                    LedgerAccount::query()->find($data['cash_ledger_id'] ?? null),
                );
                if ($source === 'manual' && ($data['lines'] ?? []) !== []) {
                    $roznamcha->saveLines($page, array_values((array) $data['lines']));
                }

                return $page;
            });
        } catch (BusinessRuleViolation $e) {
            throw ValidationException::withMessages([$source === 'manual' ? 'data.lines' : 'data.scan' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
