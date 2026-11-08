<?php

declare(strict_types=1);

namespace App\Domain\Usage;

/**
 * What the code knows about an outside call it is about to make, for its usage record: the module, the action, a
 * short reason, the transaction it belongs to, and a key so a retry is not counted twice.
 *
 *     UsageContext::run(['module' => 'Weighbridge', 'action' => 'Weight slip notification',
 *         'description' => 'Told the supplier the weighing is complete', 'related_type' => 'gate_entry',
 *         'related_id' => $entry->id, 'key' => 'slip-msg:'.$entry->id], fn () => $messenger->send(...));
 */
final class UsageContext
{
    /** @var list<array<string, string|null>> */
    private static array $stack = [];

    /**
     * @template T
     *
     * @param  array{module?: string, action?: string, description?: string, related_type?: string, related_id?: string, key?: string}  $context
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(array $context, callable $callback): mixed
    {
        self::$stack[] = array_merge(self::current(), $context);
        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @return array<string, string|null> */
    public static function current(): array
    {
        return self::$stack === [] ? [] : self::$stack[array_key_last(self::$stack)];
    }
}
