<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\Weighment;
use App\Models\Gate\WeighmentCheck;

/**
 * The second look at every recorded weight: someone holding weighment.review (the mill's Super Admin
 * or the Owner) marks it OK, or queries it with a note. Nobody checks a weight they recorded.
 */
final class WeighmentChecks
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    public function decide(Weighment $weighment, string $decision, ?string $note): WeighmentCheck
    {
        $account = $this->actor->account();
        if ($account === null || ! $account->hasPermission('weighment.review')) {
            throw new BusinessRuleViolation('Checking weights needs the weighment.review right (Super Admin or Owner).');
        }
        if (! in_array($decision, [WeighmentCheck::OK, WeighmentCheck::QUERY], true)) {
            throw new BusinessRuleViolation("Unknown decision {$decision}.");
        }
        if ($weighment->created_by !== null && $weighment->created_by === $account->id) {
            throw new BusinessRuleViolation('You recorded this weight, so someone else must check it.');
        }
        $note = $note === null ? null : (trim($note) === '' ? null : trim($note));
        if ($decision === WeighmentCheck::QUERY && ($note === null || mb_strlen($note) < 5)) {
            throw new BusinessRuleViolation('Say what is wrong with the weight (at least 5 characters).');
        }

        $check = new WeighmentCheck([
            'weighment_id' => $weighment->id,
            'decision' => $decision,
            'note' => $note,
            'checked_by' => $account->id,
            'checked_at' => now(),
        ]);
        $check->entity_id = $weighment->entity_id;
        $check->save();

        $this->audit->record('weighment.checked', $weighment, meta: ['decision' => $decision, 'note' => $note, 'check_id' => $check->id]);

        return $check;
    }
}
