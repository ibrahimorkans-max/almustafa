<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;

it('hashes passwords with Argon2id', function () {
    $hash = Hash::make('Correct-Horse-42!');

    expect(password_get_info($hash)['algoName'])->toBe('argon2id')
        ->and(Hash::check('Correct-Horse-42!', $hash))->toBeTrue()
        ->and(Hash::check('wrong', $hash))->toBeFalse();
});
