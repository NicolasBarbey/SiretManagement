<?php

declare(strict_types=1);

namespace SiretManagement\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SiretManagement\Service\IntraCommunityVatChecker;
use SiretManagement\Service\VatExistenceChecker;
use SiretManagement\Service\VatNumberVerifier;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Legal\Enum\VatVerificationStatus;

class VatNumberVerifierTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        try {
            Translator::getInstance();
        } catch (\RuntimeException) {
            new Translator(new RequestStack());
        }
    }

    private function makeVerifier(int $httpCode, ?string $body): VatNumberVerifier
    {
        $httpClient = new MockHttpClient([new MockResponse($body ?? '', ['http_code' => $httpCode])]);
        $checker = new VatExistenceChecker(new NullLogger(), new IntraCommunityVatChecker(), $httpClient);

        return new VatNumberVerifier($checker, new ArrayAdapter());
    }

    public function testAMatchIsReportedAsVerifiedWithTheViesName(): void
    {
        $verifier = $this->makeVerifier(200, json_encode([
            'countryCode' => 'FR',
            'vatNumber' => '40303265045',
            'valid' => true,
            'name' => 'SA SODIMAS',
        ]));

        $result = $verifier->verify('FR40303265045', 'FR');

        $this->assertSame(VatVerificationStatus::VERIFIED, $result->status);
        $this->assertSame('SA SODIMAS', $result->verifiedName);
    }

    public function testNoMatchIsReportedAsRefused(): void
    {
        $verifier = $this->makeVerifier(200, json_encode([
            'countryCode' => 'FR',
            'vatNumber' => '99999999999',
            'valid' => false,
        ]));

        $result = $verifier->verify('FR99999999999', 'FR');

        $this->assertSame(VatVerificationStatus::REFUSED, $result->status);
    }

    public function testAnInvalidInputErrorIsReportedAsRefused(): void
    {
        $verifier = $this->makeVerifier(200, json_encode([
            'actionSucceed' => false,
            'errorWrappers' => [['error' => 'INVALID_INPUT']],
        ]));

        $result = $verifier->verify('FR12123456789', 'FR');

        $this->assertSame(VatVerificationStatus::REFUSED, $result->status);
    }

    public function testANumberOfAnotherMemberStateIsRefusedWithoutAskingVies(): void
    {
        $calls = 0;
        $httpClient = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) json_encode(['countryCode' => 'FR', 'vatNumber' => '40303265045', 'valid' => true, 'name' => 'SA SODIMAS']));
        });
        $verifier = new VatNumberVerifier(new VatExistenceChecker(new NullLogger(), new IntraCommunityVatChecker(), $httpClient), new ArrayAdapter());

        $this->assertSame(VatVerificationStatus::REFUSED, $verifier->verify('FR40303265045', 'BE')->status);
        $this->assertSame(0, $calls);
    }

    public function testAGreekNumberCarriesThePrefixEl(): void
    {
        $verifier = $this->makeVerifier(200, json_encode([
            'countryCode' => 'EL',
            'vatNumber' => '094014201',
            'valid' => true,
            'name' => 'ACME AE',
        ]));

        $this->assertSame(VatVerificationStatus::VERIFIED, $verifier->verify('EL094014201', 'GR')->status);
    }

    public function testARefusalIsRememberedRatherThanAskedAgain(): void
    {
        $calls = 0;
        $httpClient = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) json_encode(['countryCode' => 'FR', 'vatNumber' => '99999999999', 'valid' => false]));
        });
        $verifier = new VatNumberVerifier(new VatExistenceChecker(new NullLogger(), new IntraCommunityVatChecker(), $httpClient), new ArrayAdapter());

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->assertSame(VatVerificationStatus::REFUSED, $verifier->verify('FR99999999999', 'FR')->status);
        }

        $this->assertSame(1, $calls);
    }

    public function testAMemberStateOutageReportedWithinAnAnswerIsUndeterminedAndNotRemembered(): void
    {
        $calls = 0;
        $httpClient = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) json_encode(['countryCode' => 'FR', 'vatNumber' => '40303265045', 'valid' => false, 'userError' => 'MS_UNAVAILABLE']));
        });
        $verifier = new VatNumberVerifier(new VatExistenceChecker(new NullLogger(), new IntraCommunityVatChecker(), $httpClient), new ArrayAdapter());

        $this->assertSame(VatVerificationStatus::UNDETERMINED, $verifier->verify('FR40303265045', 'FR')->status);
        $this->assertSame(VatVerificationStatus::UNDETERMINED, $verifier->verify('FR40303265045', 'FR')->status);
        $this->assertSame(2, $calls);
    }

    public function testAServiceOutageIsReportedAsUndetermined(): void
    {
        $verifier = $this->makeVerifier(503, null);

        $result = $verifier->verify('FR40303265045', 'FR');

        $this->assertSame(VatVerificationStatus::UNDETERMINED, $result->status);
    }

    public function testARateLimitErrorIsReportedAsUndetermined(): void
    {
        $verifier = $this->makeVerifier(200, json_encode([
            'actionSucceed' => false,
            'errorWrappers' => [['error' => 'MS_MAX_CONCURRENT_REQ']],
        ]));

        $result = $verifier->verify('FR40303265045', 'FR');

        $this->assertSame(VatVerificationStatus::UNDETERMINED, $result->status);
    }

    public function testANumberOutOfViesScopeIsReportedAsUndetermined(): void
    {
        $verifier = $this->makeVerifier(200, null);

        $result = $verifier->verify('FRNOTAVALIDVATNUMBER', 'FR');

        $this->assertSame(VatVerificationStatus::UNDETERMINED, $result->status);
    }
}
