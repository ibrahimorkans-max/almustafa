<?php

declare(strict_types=1);

namespace App\Models\Assistant;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One chat with the assistant, on one day, of one person (App\Livewire\Concerns\TalksToAssistant). The person
 * sees only their own chats, of their working mill.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $login_account_id
 * @property Carbon $chat_date
 * @property string|null $title the first question
 * @property list<array<string, mixed>> $messages
 * @property int $message_count
 * @property Carbon $updated_at
 */
class AssistantChat extends Model
{
    use BelongsToEntity;
    use HasUuids;

    /** Days a chat is kept. */
    public const KEEP_DAYS = 90;

    protected $fillable = ['login_account_id', 'chat_date', 'title', 'messages', 'message_count'];

    protected function casts(): array
    {
        return ['chat_date' => 'date', 'messages' => 'array', 'message_count' => 'integer'];
    }

    /** @param  Builder<self>  $query */
    public function scopeOf(Builder $query, string $accountId): void
    {
        $query->where('login_account_id', $accountId);
    }
}
