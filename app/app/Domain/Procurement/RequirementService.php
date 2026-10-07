<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Masters\Party;
use App\Models\Procurement\PaddyRequirement;
use App\Models\Procurement\RequirementRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Paddy requirements: draft → open (broadcast to brokers / farmers / traders) → closed.
 * In this phase "broadcast" records who was told, when and through which channel; recipients
 * with portal access see open requirements. SMS / WhatsApp delivery plugs in later.
 */
final class RequirementService
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): PaddyRequirement
    {
        $this->require();

        return DB::transaction(function () use ($data): PaddyRequirement {
            $requirement = new PaddyRequirement($data);
            $requirement->number = $this->numbers->next(DocumentNumbers::REQUIREMENT);
            $requirement->save();

            return $requirement;
        });
    }

    /**
     * Opens the requirement and records the broadcast to the given parties
     * (brokers, farmers, vendors). Re-broadcasting adds new recipients only.
     *
     * @param  list<string>  $partyIds
     */
    public function broadcast(PaddyRequirement $requirement, array $partyIds, string $channel = 'portal'): int
    {
        $this->require();
        if ($requirement->status === PaddyRequirement::STATUS_CLOSED) {
            throw new BusinessRuleViolation('This requirement is closed.');
        }

        return DB::transaction(function () use ($requirement, $partyIds, $channel): int {
            $parties = Party::query()->whereIn('id', $partyIds)
                ->where(fn ($q) => $q->where('is_broker', true)->orWhere('is_farmer', true)->orWhere('is_vendor', true))
                ->pluck('id');
            $already = $requirement->recipients()->pluck('party_id');
            $new = $parties->diff($already);

            foreach ($new as $partyId) {
                RequirementRecipient::query()->create([
                    'requirement_id' => $requirement->id, 'party_id' => $partyId, 'channel' => $channel, 'notified_at' => now(),
                ]);
            }

            if ($requirement->status === PaddyRequirement::STATUS_DRAFT) {
                $requirement->status = PaddyRequirement::STATUS_OPEN;
                $requirement->opened_at = now();
                $requirement->save();
            }
            $this->audit->record('procurement.requirement.broadcast', $requirement, meta: ['recipients' => $new->values()->all(), 'channel' => $channel]);

            return $new->count();
        });
    }

    public function close(PaddyRequirement $requirement): PaddyRequirement
    {
        $this->require();
        $requirement->status = PaddyRequirement::STATUS_CLOSED;
        $requirement->closed_at = now();
        $requirement->save();

        return $requirement;
    }

    private function require(): void
    {
        if (! ($this->actor->account()?->hasPermission('requirement.broadcast') ?? false)) {
            throw new BusinessRuleViolation("You need 'requirement.broadcast' for this.");
        }
    }
}
