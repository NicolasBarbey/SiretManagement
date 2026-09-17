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

        return new VatNumberVerifier($checker);
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

        $result = $verifier->verify('NOTAVALIDVATNUMBER', 'FR');

        $this->assertSame(VatVerificationStatus::UNDETERMINED, $result->status);
    }
}
