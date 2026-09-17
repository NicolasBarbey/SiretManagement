<?php

declare(strict_types=1);

namespace SiretManagement\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use SiretManagement\Service\IntraCommunityVatChecker;
use SiretManagement\Service\VatExistenceChecker;
use Symfony\Component\HttpClient\HttpClient;
use Thelia\Core\Event\Cart\CartCheckoutEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Taxation\Enum\VatExemptionMode;
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
