<?php

declare(strict_types=1);

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A software passkey for tests: an ES256 key pair and the WebAuthn messages a phone or laptop would
 * send (attestation "none" on registration, a signed assertion on sign-in), built byte for byte, so
 * the real verification in web-auth/webauthn-lib runs unchanged.
 */
final class VirtualAuthenticator
{
    private OpenSSLAsymmetricKey $key;

    public string $credentialId;

    private int $counter = 0;

    public function __construct(private readonly string $rpId = 'localhost', private readonly string $origin = 'http://localhost')
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        // Portable PHP on Windows does not find its openssl.cnf on its own.
        $cnf = getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($cnf)) {
            $options['config'] = $cnf;
        }
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new RuntimeException('OpenSSL cannot make an EC key here.');
        }
        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /** The browser's answer to navigator.credentials.create() for these options. */
    public function register(string $optionsJson): string
    {
        $options = json_decode($optionsJson, true);
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))      // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))     // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))     // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)));
        $authData = hash('sha256', $this->rpId, true).chr(0x45).pack('N', $this->counter)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.(string) $cose;
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $this->origin, 'crossOrigin' => false]);

        return json_encode([
            'id' => self::b64($this->credentialId), 'rawId' => self::b64($this->credentialId), 'type' => 'public-key',
            'response' => ['clientDataJSON' => self::b64($clientData), 'attestationObject' => self::b64((string) $attestation), 'transports' => ['internal']],
            'clientExtensionResults' => new \stdClass,
        ]);
    }

    /**
     * The browser's answer to navigator.credentials.get(): the challenge signed with the private key.
     * $userVerified = false imitates a key that checked presence only (no fingerprint, face or PIN).
     */
    public function assert(string $optionsJson, string $userId, bool $userVerified = true): string
    {
        $options = json_decode($optionsJson, true);
        $this->counter++;
        $authData = hash('sha256', $this->rpId, true).chr($userVerified ? 0x05 : 0x01).pack('N', $this->counter);
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $this->origin, 'crossOrigin' => false]);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return json_encode([
            'id' => self::b64($this->credentialId), 'rawId' => self::b64($this->credentialId), 'type' => 'public-key',
            'response' => ['clientDataJSON' => self::b64($clientData), 'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature), 'userHandle' => self::b64($userId)],
            'clientExtensionResults' => new \stdClass,
        ]);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
