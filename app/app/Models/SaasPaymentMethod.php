<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A way customers can pay the product owner (SaaS): a bank account, a mobile wallet (JazzCash,
 * Easypaisa), Raast or another, with its QR code: the picture the bank or wallet gave, or one made from
 * a text (a Raast ID, a payment link). Shown on My subscription, on the payment page and on invoices.
 *
 * @property string $id
 * @property string $name
 * @property string $kind bank | wallet | raast | other
 * @property string|null $account_title
 * @property string|null $account_number
 * @property string|null $iban
 * @property string|null $instructions
 * @property string|null $qr_path
 * @property string|null $qr_text
 * @property bool $active
 * @property int $sort
 */
class SaasPaymentMethod extends Model
{
    use Auditable;
    use HasUuids;

    public const KINDS = ['bank' => 'Bank account', 'wallet' => 'Mobile wallet', 'raast' => 'Raast', 'other' => 'Other'];

    protected $fillable = ['name', 'kind', 'account_title', 'account_number', 'iban', 'instructions', 'qr_path', 'qr_text', 'active', 'sort'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer'];
    }

    /** @param  Builder<SaasPaymentMethod>  $query */
    public function scopeOffered(Builder $query): void
    {
        $query->where('active', true)->orderBy('sort')->orderBy('name');
    }

    public function hasQr(): bool
    {
        return $this->qrImage() !== null;
    }

    /**
     * The QR as an image: [mime, bytes]. The uploaded picture first, else one made from the text.
     *
     * @return array{0: string, 1: string}|null
     */
    public function qrImage(): ?array
    {
        if ($this->qr_path !== null && Storage::disk('local')->exists($this->qr_path)) {
            return [str_ends_with($this->qr_path, '.png') ? 'image/png' : 'image/jpeg', (string) Storage::disk('local')->get($this->qr_path)];
        }
        if ($this->qr_text !== null && trim($this->qr_text) !== '') {
            $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd)))->writeString(trim($this->qr_text));

            return ['image/svg+xml', $svg];
        }

        return null;
    }

    /** For an <img> on a page or in a PDF. */
    public function qrDataUri(): ?string
    {
        $qr = $this->qrImage();

        return $qr === null ? null : 'data:'.$qr[0].';base64,'.base64_encode($qr[1]);
    }

    /** @return array<string, string> label => value, the filled ones */
    public function details(): array
    {
        return array_filter([
            'Account title' => (string) $this->account_title,
            'Account / mobile number' => (string) $this->account_number,
            'IBAN' => (string) $this->iban,
        ], fn (string $v) => $v !== '');
    }
}
