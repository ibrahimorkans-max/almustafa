<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * WebAuthn checks for this site. The library insists on HTTPS; browsers also treat
 * http://localhost as secure, so development on localhost works too. Production is HTTPS anyway.
 */
final class PasskeyCeremony extends ConfigureCeremonyStepManagerFactoryAction
{
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();
        if (config('passkeys.relying_party.id') === 'localhost') {
            $factory->setSecuredRelyingPartyId(['localhost']);
        }

        return $factory;
    }
}
