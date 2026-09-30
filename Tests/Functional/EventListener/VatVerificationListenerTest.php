<?php

declare(strict_types=1);

namespace SiretManagement\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use SiretManagement\EventListener\VatVerificationListener;
use SiretManagement\Service\IntraCommunityVatChecker;
use SiretManagement\Service\VatExistenceChecker;
use Symfony\Component\HttpClient\HttpClient;
use Thelia\Core\Event\Address\AddressCreateOrUpdateEvent;
use Thelia\Core\Event\Cart\CartCheckoutEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Legal\Service\VatNumberVerifierInterface;
use Thelia\Domain\Legal\VatVerificationResult;
use Thelia\Domain\Taxation\Enum\VatExemptionMode;
use Thelia\Model\Address;
use Thelia\Model\ConfigQuery;
use Thelia\Test\ActionIntegrationTestCase;

class VatVerificationListenerTest extends ActionIntegrationTestCase
{
    private function enableVatExemption(): void
    {
        ConfigQuery::write(VatExemptionMode::CONFIG_KEY, VatExemptionMode::VERIFIED_VAT_NUMBER->value);
    }

    public function testChoosingAnAddressOwnedByAnotherCustomerNeverVerifiesIt(): void
    {
        $this->enableVatExemption();

        $owner = $this->factory->customer($this->factory->customerTitle());
        $ownerAddress = $this->factory->address($owner, $this->factory->country());
        $ownerAddress->setVatNumber('FR40303265045')->save($this->getPropelConnection());

        $attacker = $this->factory->customer($this->factory->customerTitle());
        $attackerCart = $this->factory->cart($attacker);

        $event = new CartCheckoutEvent($attackerCart);
        $event->setInvoiceAddressId($ownerAddress->getId());
        $this->dispatch($event, TheliaEvents::CART_SET_INVOICE_ADDRESS);

        $ownerAddress->reload();
        self::assertNull($ownerAddress->getVatVerifiedAt());
    }

    public function testExemptionModeDisabledNeverVerifiesEvenWithAVatNumber(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $address = $this->factory->address($customer, $this->factory->country());
        $address->setVatNumber('FR40303265045')->save($this->getPropelConnection());
        $cart = $this->factory->cart($customer);

        $event = new CartCheckoutEvent($cart);
        $event->setInvoiceAddressId($address->getId());
        $this->dispatch($event, TheliaEvents::CART_SET_INVOICE_ADDRESS);

        $address->reload();
        self::assertNull($address->getVatVerifiedAt());
    }

    public function testNoVatNumberIsNeverVerified(): void
    {
        $this->enableVatExemption();

        $customer = $this->factory->customer($this->factory->customerTitle());
        $address = $this->factory->address($customer, $this->factory->country());
        $cart = $this->factory->cart($customer);

        $event = new CartCheckoutEvent($cart);
        $event->setInvoiceAddressId($address->getId());
        $this->dispatch($event, TheliaEvents::CART_SET_INVOICE_ADDRESS);

        $address->reload();
        self::assertNull($address->getVatVerifiedAt());
    }

    public function testSavingAnAddressWithANumberAsksForAVerificationOnce(): void
    {
        $this->enableVatExemption();
        [$listener, $verifier] = $this->listenerWithACountingVerifier();
        $address = $this->addressWithANumber();

        $listener->verifyOnAddressSave($this->addressSaved($address));

        self::assertSame(1, $verifier->calls);
        $address->reload();
        self::assertSame('ACME SA', $address->getVatVerifiedName());
    }

    public function testSavingAgainWhileTheVerificationIsValidDoesNotAskAgain(): void
    {
        $this->enableVatExemption();
        [$listener, $verifier] = $this->listenerWithACountingVerifier();
        $address = $this->addressWithANumber();

        $listener->verifyOnAddressSave($this->addressSaved($address));
        $address->setCity('Liège')->save($this->getPropelConnection());
        $listener->verifyOnAddressSave($this->addressSaved($address));

        self::assertSame(1, $verifier->calls);
    }

    public function testSavingAnotherNumberAsksAgain(): void
    {
        $this->enableVatExemption();
        [$listener, $verifier] = $this->listenerWithACountingVerifier();
        $address = $this->addressWithANumber();

        $listener->verifyOnAddressSave($this->addressSaved($address));
        $address->setVatNumber('BE0987654321')->save($this->getPropelConnection());
        $listener->verifyOnAddressSave($this->addressSaved($address));

        self::assertSame(2, $verifier->calls);
    }

    public function testAnExpiredVerificationIsAskedAgain(): void
    {
        $this->enableVatExemption();
        [$listener, $verifier] = $this->listenerWithACountingVerifier();
        $address = $this->addressWithANumber();
        $address
            ->setVatVerifiedAt(new \DateTime(\sprintf('-%d days', ConfigQuery::getVatVerificationLifetimeDays() + 5)))
            ->setVatVerifiedName('ACME SA')
            ->save($this->getPropelConnection());

        $listener->verifyOnAddressSave($this->addressSaved($address));

        self::assertSame(1, $verifier->calls);
    }

    /**
     * @return array{VatVerificationListener, object}
     */
    private function listenerWithACountingVerifier(): array
    {
        $verifier = new class implements VatNumberVerifierInterface {
            public int $calls = 0;

            public function verify(string $vatNumber, string $countryIsoAlpha2): VatVerificationResult
            {
                ++$this->calls;

                return VatVerificationResult::verified(new \DateTimeImmutable(), 'ACME SA');
            }
        };

        return [
            new VatVerificationListener($verifier, $this->getService('event_dispatcher'), new NullLogger()),
            $verifier,
        ];
    }

    private function addressWithANumber(): Address
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $belgium = $this->factory->country(['isocode' => 'BE', 'isoalpha2' => 'BE', 'isoalpha3' => 'BEX']);
        $address = $this->factory->address($customer, $belgium);
        $address->setCompany('Acme')->setVatNumber('BE0123456789')->save($this->getPropelConnection());

        return $address;
    }

    private function addressSaved(Address $address): AddressCreateOrUpdateEvent
    {
        $event = new AddressCreateOrUpdateEvent(
            (string) $address->getLabel(),
            $address->getTitleId(),
            (string) $address->getFirstname(),
            (string) $address->getLastname(),
            (string) $address->getAddress1(),
            '',
            '',
            (string) $address->getZipcode(),
            (string) $address->getCity(),
            $address->getCountryId(),
            '',
            '',
            (string) $address->getCompany(),
            0,
            null,
            null,
            $address->getVatNumber(),
        );
        $event->setAddress($address);

        return $event;
    }

    #[Group('functional')]
    public function testChoosingOwnAddressWithARealVatNumberVerifiesItAgainstLiveVies(): void
    {
        $checker = new VatExistenceChecker(new NullLogger(), new IntraCommunityVatChecker(), HttpClient::create());
        $availability = $checker->checkApiAvailability();
        if (!$availability['available']) {
            $this->markTestSkipped('VIES is currently unreachable: '.$availability['message']);
        }

        $this->enableVatExemption();

        $customer = $this->factory->customer($this->factory->customerTitle());
        $address = $this->factory->address($customer, $this->factory->country());
        $address->setVatNumber('FR40303265045')->save($this->getPropelConnection());
        $cart = $this->factory->cart($customer);

        $event = new CartCheckoutEvent($cart);
        $event->setInvoiceAddressId($address->getId());
        $this->dispatch($event, TheliaEvents::CART_SET_INVOICE_ADDRESS);

        $address->reload();
        self::assertNotNull($address->getVatVerifiedAt());
        self::assertSame('SA SODIMAS', $address->getVatVerifiedName());
    }
}
