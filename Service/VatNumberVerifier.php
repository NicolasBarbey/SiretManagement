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

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Thelia\Domain\Legal\Enum\VatVerificationStatus;
use Thelia\Domain\Legal\Service\VatNumberVerifierInterface;
use Thelia\Domain\Legal\VatVerificationResult;

#[AsAlias(VatNumberVerifierInterface::class)]
final readonly class VatNumberVerifier implements VatNumberVerifierInterface
{
    private const int REFUSAL_KEPT_FOR_SECONDS = 600;

    private const array PREFIX_BY_COUNTRY = ['GR' => 'EL'];

    public function __construct(
        private VatExistenceChecker $vatExistenceChecker,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function verify(string $vatNumber, string $countryIsoAlpha2): VatVerificationResult
    {
        $normalized = strtoupper(str_replace(' ', '', $vatNumber));
        $country = strtoupper($countryIsoAlpha2);

        if (!str_starts_with($normalized, self::PREFIX_BY_COUNTRY[$country] ?? $country)) {
            return VatVerificationResult::refused(new \DateTimeImmutable());
        }

        $refusal = $this->cache->getItem('siret_management.vat_refused.'.hash('xxh128', $normalized));

        if ($refusal->isHit()) {
            return VatVerificationResult::refused(new \DateTimeImmutable());
        }

        $result = $this->answerOfVies($normalized);

        if (VatVerificationStatus::REFUSED === $result->status) {
            $this->cache->save($refusal->set(true)->expiresAfter(self::REFUSAL_KEPT_FOR_SECONDS));
        }

        return $result;
    }

    private function answerOfVies(string $vatNumber): VatVerificationResult
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
