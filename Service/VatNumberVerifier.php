<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SiretManagement\Service;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Thelia\Domain\Legal\Service\VatNumberVerifierInterface;
use Thelia\Domain\Legal\VatVerificationResult;

/**
 * Adapts VatExistenceChecker's VIES answer to the core's three-state contract.
 *
 * VatExistenceChecker already tells a client input error apart from a service
 * outage; this only renames that distinction to the vocabulary the core
 * expects. The country argument is unused: an EU VAT number carries its own
 * country prefix, which is what VatExistenceChecker parses and sends to VIES.
 */
#[AsAlias(VatNumberVerifierInterface::class)]
final readonly class VatNumberVerifier implements VatNumberVerifierInterface
{
    public function __construct(
        private VatExistenceChecker $vatExistenceChecker,
    ) {
    }

    public function verify(string $vatNumber, string $countryIsoAlpha2): VatVerificationResult
    {
        $outcome = $this->vatExistenceChecker->checkExistence($vatNumber);

        if (!$outcome['ok']) {
            return $outcome['transient']
                ? VatVerificationResult::undetermined()
                : VatVerificationResult::refused(new \DateTimeImmutable());
        }

        return $outcome['valid']
            ? VatVerificationResult::verified(new \DateTimeImmutable(), $outcome['name'])
            : VatVerificationResult::refused(new \DateTimeImmutable());
    }
}
