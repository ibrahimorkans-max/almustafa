<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Database\Eloquent\Model;

/**
 * A record that keeps where it was entered (`captured_location`), when the mill records locations and
 * the form held a position (EntryLocation::hold).
 *
 * @mixin Model
 */
trait RecordsEntryLocation
{
    public static function bootRecordsEntryLocation(): void
    {
        static::creating(function (Model $model): void {
            $location = EntryLocation::take();
            if ($location !== null && $model->getAttribute('captured_location') === null) {
                $model->setAttribute('captured_location', $location);
            }
        });
    }

    public function initializeRecordsEntryLocation(): void
    {
        $this->mergeCasts(['captured_location' => 'array']);
    }
}
