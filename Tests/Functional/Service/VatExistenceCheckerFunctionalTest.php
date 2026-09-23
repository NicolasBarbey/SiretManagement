<?php

declare(strict_types=1);

namespace SiretManagement\Tests\Functional\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SiretManagement\Service\IntraCommunityVatChecker;
use SiretManagement\Service\VatExistenceChecker;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Translation\Translator;

/**
 * Functional test: makes REAL HTTP calls to the live VIES API (ec.europa.eu).
 * Not mocked, unlike SiretManagement\Tests\Unit\Service\VatExistenceCheckerTest.
 * Requires network access; skipped automatically if VIES is unreachable so CI
 * doesn't fail on an unrelated network issue.
 *
 * @group functional
 *
 * Tagged so a CI pipeline can exclude it by default (`--exclude-group functional`):
 * repeated runs contribute to VIES's own rate limiting (MS_MAX_CONCURRENT_REQ,
 * observed live during development) and this should only run deliberately.
 */
class VatExistenceCheckerFunctionalTest extends TestCase
{
    private VatExistenceChecker $checker;

    public static function setUpBeforeClass(): void
    {
        try {
            Translator::getInstance();
        } catch (\RuntimeException) {
            new Translator(new RequestStack());
        }
    }

    protected function setUp(): void
    {
        $this->checker = new VatExistenceChecker(new NullLogger(), new IntraCommunityVatChecker(), HttpClient::create());

        $availability = $this->checker->checkApiAvailability();
        if (!$availability['available']) {
            $this->markTestSkipped('VIES is currently unreachable: '.$availability['message']);
        }
    }

    public function testCheckApiAvailabilityAgainstLiveVies(): void
    {
        $result = $this->checker->checkApiAvailability();

        $this->assertTrue($result['available']);
    }

    public function testCheckDoesNotThrowForARealExistingFrenchVatNumber(): void
    {
        // SA SODIMAS, verified live during development
        $this->checker->check('FR40303265045');
        $this->addToAssertionCount(1);
    }

    public function testCheckDoesNotThrowForASyntacticallyValidButNonExistingVatNumber(): void
    {
        $this->checker->check('FR99999999999');
        $this->addToAssertionCount(1);
    }

    public function testTestVatNumberAgainstLiveViesForAnExistingNumber(): void
    {
        $result = $this->checker->testVatNumber('FR40303265045');

        $this->assertTrue($result['success']);
    }

    public function testTestVatNumberAgainstLiveViesForANonExistingNumber(): void
    {
        $result = $this->checker->testVatNumber('FR99999999999');

        $this->assertTrue($result['success']);
    }

    public function testTestVatNumberAgainstLiveViesForAnInvalidFormat(): void
    {
        $result = $this->checker->testVatNumber('NOTAVALIDVATNUMBER');

        $this->assertFalse($result['success']);
    }

    public function testCheckExistenceAgainstLiveViesForAnExistingNumber(): void
    {
        $outcome = $this->checker->checkExistence('FR40303265045');

        $this->assertTrue($outcome['ok']);
        $this->assertTrue($outcome['valid']);
        $this->assertSame('SA SODIMAS', $outcome['name']);
    }

    public function testCheckExistenceAgainstLiveViesForANonExistingNumber(): void
    {
        $outcome = $this->checker->checkExistence('FR99999999999');

        $this->assertTrue($outcome['ok']);
        $this->assertFalse($outcome['valid']);
    }

    public function testCheckExistenceAgainstLiveViesForAnInvalidFormat(): void
    {
        $outcome = $this->checker->checkExistence('NOTAVALIDVATNUMBER');

        $this->assertFalse($outcome['ok']);
        $this->assertTrue($outcome['transient']);
    }
}
