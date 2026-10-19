<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Approvals\SelfApproval;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Domain\Procurement\SaudaService;
use App\Filament\Support\PrintButton;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Support\Values\Money;

/** A purchase sauda: seller, paddy, maund and rate; sent for approval (applied at once for the Owner / Super Admin). */
final class CreateSaudaTool extends Tool
{
    public function name(): string
    {
        return 'create_purchase_sauda';
    }

    public function description(): string
    {
        return 'Make a paddy purchase deal (sauda): seller (part of the name), product (part of the name, e.g. "1121"), quantity in maund, rate in rupees per maund, '
            .'and delivery days (default 30). It is sent for approval; the Owner\'s and Super Admin\'s own sauda is confirmed at once.';
    }

    public function properties(): array
    {
        return [
            'seller' => ['type' => 'string'], 'product' => ['type' => 'string'],
            'maund' => ['type' => 'number'], 'rate_per_maund' => ['type' => 'number', 'description' => 'Rupees per maund.'],
            'days' => ['type' => 'integer', 'description' => 'Delivery window in days, default 30.'],
        ];
    }

    public function required(): array
    {
        return ['seller', 'product', 'maund', 'rate_per_maund'];
    }

    public function permission(): string
    {
        return 'deal.create';
    }

    public function writes(): bool
    {
        return true;
    }

    public function patterns(): array
    {
        return ['/^(?:sauda|buy|purchase)\s+(?<maund>[\d,.]+)\s+maund\s+(?<product>[\w ]{2,30}?)\s+from\s+(?<seller>[\w .&]{2,50}?)\s+(?:at|@)\s+(?:rs\.?\s*)?(?<rate_per_maund>[\d,.]+)(?:\s+(?<days>\d{1,3})\s+days)?$/i'];
    }

    public function examples(): array
    {
        return ['sauda 500 maund 1121 from Aslam at 4200'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        [$party, $product] = [$this->party((string) ($input['seller'] ?? '')), $this->product((string) ($input['product'] ?? ''))];

        return 'Purchase sauda: '.$this->num($input['maund'] ?? 0).' maund of '.($product === null ? $input['product'] : $product->name).' from '
            .($party === null ? $input['seller'] : $party->name).' at '.Money::rupees($this->num($input['rate_per_maund'] ?? 0))->format().' per maund, delivery within '.((int) ($input['days'] ?? 30)).' days.';
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $party = $this->party((string) $input['seller']);
        $product = $this->product((string) $input['product']);
        if ($party === null || $product === null) {
            return ToolResult::fail($party === null ? 'No seller matches "'.$input['seller'].'".' : 'No paddy product matches "'.$input['product'].'".');
        }
        $service = app(SaudaService::class);
        $sauda = $service->submit($service->create([
            'party_id' => $party->id, 'product_id' => $product->id,
            'quantity_kg' => bcmul($this->num($input['maund']), '40', 3), 'rate_paise_per_maund' => Money::rupees($this->num($input['rate_per_maund']))->paise,
            'delivery_from' => now('Asia/Karachi')->toDateString(), 'delivery_to' => now('Asia/Karachi')->addDays((int) ($input['days'] ?? 30))->toDateString(),
        ]));
        if (SelfApproval::allowed($me) && $me->hasPermission('deal.approve')) {
            $sauda = $service->approve($sauda, 'Made and confirmed by the '.($me->isOwner() ? 'Owner' : 'Super Admin').' through the assistant');
        }

        return new ToolResult("Sauda {$sauda->number} ".($sauda->status === 'confirmed' ? 'confirmed' : 'sent for approval').'.', [['label' => 'Print sauda contract', 'url' => PrintButton::url('sauda', $sauda)]]);
    }

    private function party(string $words): ?Party
    {
        $like = '%'.mb_strtolower(trim($words)).'%';

        return trim($words) === '' ? null : Party::query()->where(fn ($q) => $q->where('is_farmer', true)->orWhere('is_vendor', true))
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]))->orderBy('name')->first();
    }

    private function product(string $words): ?Product
    {
        $like = '%'.mb_strtolower(trim($words)).'%';

        return trim($words) === '' ? null : Product::query()->where('category', 'paddy')
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]))->orderBy('name')->first();
    }

    private function num(mixed $v): string
    {
        return (string) str_replace(',', '', (string) $v);
    }
}
