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

namespace SiretManagement\EventListener;

use Psr\Log\LoggerInterface;
use SiretManagement\SiretManagement;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Core\Event\Cart\CartCheckoutEvent;
use Thelia\Core\Event\Legal\VatNumberVerifiedEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Legal\Service\VatNumberVerifierInterface;
use Thelia\Model\Address;
use Thelia\Model\AddressQuery;
use Thelia\Model\ConfigQuery;

/**
 * Triggers a VAT number verification wherever an address becomes the one an
 * order is billed on: when it is saved to the address book, and again when it
 * is picked as the invoice address in the checkout tunnel, since the number
 * may have gone stale since it was last checked there.
 *
 * The invoice-address listener runs ahead of Thelia\Action\Cart::
 * setInvoiceAddress() (priority 128): CartAddressService::
 * getOrCreateCartAddressFromAddress() takes a frozen copy of the address at
 * that moment, so the address must already carry an up-to-date answer before
 * it is copied.
 */
final class VatVerificationListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly VatNumberVerifierInterface $vatNumberVerifier,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function verifyOnAddressSave(AddressCreateOrUpdateEvent $event): void
    {
        $this->verifyIfNeeded($event->getAddress());
    }

    public function verifyOnInvoiceAddressChoice(CartCheckoutEvent $event): void
    {
        $addressId = $event->getInvoiceAddressId();
        if (null === $addressId) {
            return;
        }

        $address = AddressQuery::create()->findPk($addressId);
        if (!$address instanceof Address) {
            return;
        }

        $this->verifyIfNeeded($address);
    }

    private function verifyIfNeeded(Address $address): void
    {
        if (!(bool) SiretManagement::getConfigValue(SiretManagement::VAT_API_CHECK_ENABLED, null)) {
            return;
        }

        $vatNumber = $address->getVatNumber();
        if (null === $vatNumber || '' === $vatNumber || !$this->hasExpired($address)) {
            return;
        }

        $countryIsoAlpha2 = $address->getCountry()?->getIsoalpha2();
        if (null === $countryIsoAlpha2) {
            return;
        }

        try {
            $result = $this->vatNumberVerifier->verify($vatNumber, $countryIsoAlpha2);
        } catch (\Throwable $exception) {
            // VatNumberVerifierInterface must answer UNDETERMINED for an outage rather
            // than throw; anything caught here is a bug in the implementation, not
            // something the address book or the tunnel should break on.
            $this->logger->error('Unexpected error while verifying a VAT number', ['exception' => $exception]);

            return;
        }

        $this->dispatcher->dispatch(
            new VatNumberVerifiedEvent($address, $result),
            TheliaEvents::VAT_NUMBER_VERIFIED
        );
    }

    /**
     * True as long as no answer is recorded, or the last one is older than the
     * shop's configured lifetime - never on every display, per
     * Thelia\Domain\Taxation\Service\VatExemptionResolver's own aging rule.
     */
    private function hasExpired(Address $address): bool
    {
        $verifiedAt = $address->getVatVerifiedAt();
        if (null === $verifiedAt) {
            return true;
        }

        $expiresAt = \DateTimeImmutable::createFromInterface($verifiedAt)
            ->modify(\sprintf('+%d days', ConfigQuery::getVatVerificationLifetimeDays()));

        return $expiresAt < new \DateTimeImmutable();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ADDRESS_CREATE => ['verifyOnAddressSave', 50],
            TheliaEvents::ADDRESS_UPDATE => ['verifyOnAddressSave', 50],
            TheliaEvents::CART_SET_INVOICE_ADDRESS => ['verifyOnInvoiceAddressChoice', 130],
        ];
    }
}
