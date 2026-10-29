<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * "Mobile camera" for a roznamcha page entered on a computer: the computer shows a QR code (and the link);
 * the phone opens it, takes the photo with its camera and sends it; the computer's form picks it up.
 *
 * The link is signed and lasts 30 minutes. It can only send one picture to that one waiting form (it
 * shows nothing of the mill's data), and the picture is stored for the mill and the person who started it.
 */
final class RoznamchaCapture
{
    public const MINUTES = 30;

    public function __construct(private readonly TenantContext $tenant) {}

    /** A new capture for the person and mill working now; returns its token. */
    public function start(string $accountId): string
    {
        $entityId = $this->tenant->entityId() ?? throw new BusinessRuleViolation('Choose the mill first.');
        $token = Str::random(40);
        Cache::put(self::key($token), ['entity_id' => $entityId, 'account_id' => $accountId, 'file_id' => null], now()->addMinutes(self::MINUTES));

        return $token;
    }

    /** The phone's link. */
    public static function url(string $token): string
    {
        return URL::temporarySignedRoute('roznamcha.capture', now()->addMinutes(self::MINUTES), ['token' => $token]);
    }

    /** The QR code of the phone's link (SVG). */
    public static function qr(string $token): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString(self::url($token));
    }

    /** @return array{entity_id: string, account_id: string, file_id: string|null}|null */
    public static function find(string $token): ?array
    {
        $c = Cache::get(self::key($token));

        return is_array($c) ? $c : null;
    }

    /** The phone sent the photo: it is kept for the mill (content-addressed, SHA-256). One photo per capture. */
    public function receive(string $token, string $bytes): string
    {
        $c = self::find($token) ?? throw new BusinessRuleViolation('This link has expired. Make a new one on the computer.');
        if ($c['file_id'] !== null) {
            throw new BusinessRuleViolation('A photo was already sent with this link.');
        }
        $file = $this->tenant->runInEntity($c['entity_id'], fn () => app(FileStore::class)->put($bytes));
        if (! in_array($file->mime, ['image/jpeg', 'image/png'], true)) {
            throw new BusinessRuleViolation('Send a photo (JPEG or PNG).');
        }
        Cache::put(self::key($token), ['file_id' => $file->id] + $c, now()->addMinutes(self::MINUTES));

        return $file->id;
    }

    /** The photo sent for this capture, only to the person who started it (the token itself is secret). */
    public function received(string $token, string $accountId): ?string
    {
        $c = self::find($token);

        return $c !== null && $c['account_id'] === $accountId ? $c['file_id'] : null;
    }

    private static function key(string $token): string
    {
        return 'roznamcha.capture.'.hash('sha256', $token);
    }
}
