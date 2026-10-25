<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Security messages to the people who must know: the Super Admins (of one firm, or of all firms), the Owners, and the configured security contacts. In-app notification + e-mail; SMS numbers are
 * handed to the SMS channel (log only until a provider is configured). Failure to deliver one
 * message never stops the security action itself.
 */
final class SecurityNotifier
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Within one customer (SaaS): a mill's event reaches that mill's Super Admins and its customer's Owners;
     * an event of all firms reaches the current customer's Super Admins and Owners; an event of the whole
     * system (no customer) reaches every Owner and Super Admin. Otherwise never another customer.
     *
     * @param  string|null  $entityId  null = every firm (of the current customer)
     * @return int number of people reached in-app
     */
    public function owners(?string $entityId, string $title, string $body, bool $includeOwners = true): int
    {
        $customer = $entityId !== null
            ? $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('customer_id'))
            : $this->tenant->customerId();
        $people = $this->tenant->runAsSystem(fn () => LoginAccount::query()->with(['role', 'person'])
            ->where('status', LoginAccount::STATUS_ACTIVE)
            ->when($customer !== null, fn ($q) => $q->whereIn('entity_id', Entity::query()->where('customer_id', $customer)->select('id')))
            ->get()
            ->filter(fn (LoginAccount $a) => $customer === null
                // The whole system (e.g. the platform's lockdown) concerns every customer's Owners and Super Admins.
                ? ($a->isOwner() || $a->role?->code === 'SUPER_ADMIN')
                : (($a->role?->code === 'SUPER_ADMIN' && ($entityId === null || $a->entity_id === $entityId)) || ($includeOwners && $a->isOwner())))
            ->values());

        return $this->send($people, $title, $body);
    }

    /** @param  Collection<int, LoginAccount>  $people */
    public function send(Collection $people, string $title, string $body): int
    {
        try {
            if ($people->isNotEmpty()) {
                Notification::make()->title($title)->body($body)->danger()->sendToDatabase($people);
            }
        } catch (Throwable $e) {
            Log::critical('Security notification (in-app) failed', ['title' => $title, 'error' => $e->getMessage()]);
        }

        $emails = $people->map(fn (LoginAccount $a) => $a->person?->email)->filter()
            ->merge(self::list((string) config('ricemill.security.contact_emails')))->unique()->values();
        foreach ($emails as $email) {
            try {
                Mail::raw("{$title}\n\n{$body}\n\nTime: ".now()->timezone('Asia/Karachi')->format('d M Y H:i:s').' PKT',
                    fn ($m) => $m->to($email)->subject('[Rice Mill ERP security] '.$title));
            } catch (Throwable $e) {
                Log::critical('Security e-mail failed', ['title' => $title, 'error' => $e->getMessage()]);
            }
        }
        foreach (self::list((string) config('ricemill.security.contact_sms')) as $number) {
            // SMS channel: no provider yet — recorded so the hand-over to a gateway is one class.
            Log::channel((string) config('ricemill.security.sms_log_channel', 'stack'))->alert('SMS to '.self::mask($number).": {$title}");
        }

        return $people->count();
    }

    /** @return list<string> */
    private static function list(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    private static function mask(string $number): string
    {
        return str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4);
    }
}
