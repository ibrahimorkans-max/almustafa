<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyAgentSignature;

/*
 * Shared test vector: agent/tests/test_signing.py asserts the same signature, so the Python
 * agent and the server can never drift apart silently.
 */
it('matches the published agent signature test vector', function () {
    $canonical = VerifyAgentSignature::canonical('1791360000', 'nonce-0000000000000001', 'post', '/api/v1/sync/push?x=1', '{"events":[]}');

    expect(hash_hmac('sha256', $canonical, 'test-secret-do-not-use'))
        ->toBe('978e446264d4092aa50a22bc5b2687a349e67cba7a8ae6b49d65b09a46214081');
});
