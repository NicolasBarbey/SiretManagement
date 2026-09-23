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

use Psr\Log\LoggerInterface;
use SiretManagement\SiretManagement;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thelia\Core\Translation\Translator;

final readonly class VatExistenceChecker
{
    private const CHECK_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
    private const STATUS_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-status';

    /** Member states (and Northern Ireland's VIES-specific code) covered by VIES. */
    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE',
        'SI', 'SK', 'XI',
    ];

    /**
     * VIES error codes explicitly known to be client input errors. Anything else
     * reported via {actionSucceed:false, errorWrappers:[...]} is treated as a
     * service-side issue (fail-safe: unrecognized code = blocking, not silently
     * accepted as "invalid input").
     */
    private const NON_TRANSIENT_ERROR_CODES = ['INVALID_INPUT', 'INVALID_QUERY_CONFIG_TYPE'];

    public function __construct(
        private LoggerInterface $logger,
        private IntraCommunityVatChecker $formatChecker,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Checks an Intra-Community VAT number against VIES (EU Commission).
     *
     * Non-blocking: a "not found"/invalid-input result is only logged, and returned as an
     * informational notice the caller may surface to the user without rejecting the input.
     * Blocking: any service unavailability throws, so the caller can
     * surface an explicit "try again later" error.
     *
     * @return string|null a user-facing notice when VIES answered but found no match, null otherwise
     *
     * @throws \RuntimeException when VIES cannot be reached or answers with an error
     */
    public function check(string $vatNumber): ?string
    {
        $parsed = $this->parseVatNumber($vatNumber);
        if (null === $parsed) {
            // Not a recognizable EU VAT number format: out of scope for this check
            return null;
        }

        [$countryCode, $number] = $parsed;
        $result = $this->queryVies($countryCode, $number);

        if (!$result['ok']) {
            if ($result['transient']) {
                throw new \RuntimeException($this->unavailableMessage());
            }

            // Country code only, not the full number: a VAT number identifies a business and
            // shouldn't accumulate in clear text in centralized logging (Sentry, CloudWatch...).
            $this->logger->warning('SiretManagement: VIES rejected VAT number', [
                'countryCode' => $countryCode,
                'errorCode' => $result['errorCode'],
            ]);

            return null;
        }

        if (!$result['valid']) {
            $this->logger->warning('SiretManagement: no VAT number found by VIES', ['countryCode' => $countryCode]);

            return Translator::getInstance()->trans(
                'This VAT number was not found. Your account will still be created, but you may want to double-check it.',
                [],
                SiretManagement::DOMAIN_NAME
            );
        }

        return null;
    }

    /**
     * Checks whether VIES is reachable, regardless of the configured check being enabled.
     * No authentication is required for this endpoint.
     *
     * @return array{available: bool, message: string}
     */
    public function checkApiAvailability(): array
    {
        $response = $this->httpGet(self::STATUS_URL);

        if ($response['error'] || 200 !== $response['httpCode']) {
            return [
                'available' => false,
                'message' => Translator::getInstance()->trans(
                    'The VIES API is not reachable (HTTP %code).',
                    ['%code' => $response['httpCode']],
                    SiretManagement::DOMAIN_NAME
                ),
            ];
        }

        $data = json_decode((string) $response['body'], true);

        if (!\is_array($data) || true !== ($data['vow']['available'] ?? null)) {
            return [
                'available' => false,
                'message' => Translator::getInstance()->trans(
                    'The VIES API is currently unavailable.',
                    [],
                    SiretManagement::DOMAIN_NAME
                ),
            ];
        }

        return [
            'available' => true,
            'message' => Translator::getInstance()->trans(
                'The VIES API is available.',
                [],
                SiretManagement::DOMAIN_NAME
            ),
        ];
    }

    /**
     * Tests a real VAT number against VIES, for the back-office "test my configuration" button.
     * Unlike check(), this never throws: it always returns a structured, explicit result.
     *
     * `found` is only meaningful when `success` is true: it distinguishes a real match
     * (green in the BO) from "VIES answered but found nothing" (orange: the configuration
     * works, but this particular number isn't a match), so the two aren't shown identically.
     *
     * @return array{success: bool, found: bool, message: string}
     */
    public function testVatNumber(string $vatNumber): array
    {
        $parsed = $this->parseVatNumber($vatNumber);
        if (null === $parsed) {
            return [
                'success' => false,
                'found' => false,
                'message' => Translator::getInstance()->trans(
                    'This does not look like a valid EU Intra-Community VAT number.',
                    [],
                    SiretManagement::DOMAIN_NAME
                ),
            ];
        }

        [$countryCode, $number] = $parsed;
        $result = $this->queryVies($countryCode, $number);

        if (!$result['ok']) {
            if ($result['transient']) {
                return [
                    'success' => false,
                    'found' => false,
                    'message' => $this->unavailableMessage(),
                ];
            }

            return [
                'success' => false,
                'found' => false,
                'message' => Translator::getInstance()->trans(
                    'VIES rejected this input (%error). Check the VAT number format.',
                    ['%error' => $result['errorCode']],
                    SiretManagement::DOMAIN_NAME
                ),
            ];
        }

        if ($result['valid']) {
            return [
                'success' => true,
                'found' => true,
                'message' => Translator::getInstance()->trans(
                    'Configuration is functional: VIES found a valid match for this VAT number (%name).',
                    ['%name' => $result['name'] ?? '?'],
                    SiretManagement::DOMAIN_NAME
                ),
            ];
        }

        return [
            'success' => true,
            'found' => false,
            'message' => Translator::getInstance()->trans(
                'Configuration is functional: VIES answered, but no record matches this VAT number.',
                [],
                SiretManagement::DOMAIN_NAME
            ),
        ];
    }

    /**
     * Answers whether VIES confirms, denies, or cannot say whether a VAT number
     * exists, for SiretManagement\Service\VatNumberVerifier to translate into the
     * core's three-state contract (Thelia\Domain\Legal\Service\VatNumberVerifierInterface).
     *
     * Unlike check(), never throws: an outage is reported as `ok: false,
     * transient: true` rather than raised, since the contract requires an
     * answer, not an exception.
     *
     * @return array{ok: bool, transient: bool, valid: bool, name: ?string}
     */
    public function checkExistence(string $vatNumber): array
    {
        $parsed = $this->parseVatNumber($vatNumber);
        if (null === $parsed) {
            // Out of VIES's scope (non-EU country, or a shape it never covers): no
            // answer, not a refusal.
            return ['ok' => false, 'transient' => true, 'valid' => false, 'name' => null];
        }

        [$countryCode, $number] = $parsed;
        $result = $this->queryVies($countryCode, $number);

        return [
            'ok' => $result['ok'],
            'transient' => $result['transient'],
            'valid' => $result['valid'],
            'name' => $result['name'],
        ];
    }

    /**
     * @return array{0: string, 1: string}|null [countryCode, number] or null if not a recognizable EU VAT number
     */
    private function parseVatNumber(string $vatNumber): ?array
    {
        $normalized = strtoupper(str_replace(' ', '', $vatNumber));

        // Delegates to the module's shared per-country format/length validator, so an
        // obviously malformed number (e.g. wrong digit count for its country) is rejected
        // here rather than silently sent to VIES, which answers "not found" either way and
        // would otherwise be misread as "configuration functional".
        try {
            $this->formatChecker->check($normalized);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!preg_match('/^([A-Z]{2})([0-9A-Z]+)$/', $normalized, $matches)) {
            return null;
        }

        if (!\in_array($matches[1], self::EU_COUNTRY_CODES, true)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * Calls VIES check-vat-number and normalizes the result, shared by check() and
     * testVatNumber() so the (non-trivial) response parsing lives in a single place.
     *
     * @return array{ok: bool, transient: bool, valid: bool, errorCode: ?string, name: ?string}
     */
    private function queryVies(string $countryCode, string $number): array
    {
        $response = $this->httpPost(self::CHECK_URL, [
            'countryCode' => $countryCode,
            'vatNumber' => $number,
        ]);

        if ($response['error'] || 200 !== $response['httpCode']) {
            return ['ok' => false, 'transient' => true, 'valid' => false, 'errorCode' => null, 'name' => null];
        }

        $data = json_decode((string) $response['body'], true);

        if (!\is_array($data)) {
            // VIES answered 200 with an empty/unparsable body: a real outage, not "not found".
            return ['ok' => false, 'transient' => true, 'valid' => false, 'errorCode' => null, 'name' => null];
        }

        if (false === ($data['actionSucceed'] ?? true)) {
            $errorCode = $data['errorWrappers'][0]['error'] ?? 'unknown error';

            return [
                'ok' => false,
                'transient' => $this->isTransientError($errorCode),
                'valid' => false,
                'errorCode' => $errorCode,
                'name' => null,
            ];
        }

        return [
            'ok' => true,
            'transient' => false,
            'valid' => true === ($data['valid'] ?? null),
            'errorCode' => null,
            'name' => $data['name'] ?? null,
        ];
    }

    /**
     * VIES reports transient/service-side issues (rate limiting, member-state outage,
     * timeouts) through the same {actionSucceed:false, errorWrappers:[...]} schema as
     * genuine client input errors. Observed live: MS_MAX_CONCURRENT_REQ under load.
     * These must be treated as "unavailable" (blocking), not "invalid input" (non-blocking).
     *
     * Fail-safe: only a code explicitly recognized as a client input error is treated as
     * non-transient. An unrecognized VIES error code is treated as a service issue
     * (blocking) rather than silently accepted as "invalid input" (non-blocking).
     */
    private function isTransientError(string $errorCode): bool
    {
        return !\in_array($errorCode, self::NON_TRANSIENT_ERROR_CODES, true);
    }

    private function unavailableMessage(): string
    {
        return Translator::getInstance()->trans(
            'The VAT number verification service is temporarily unavailable, please try again later.',
            [],
            SiretManagement::DOMAIN_NAME
        );
    }

    /**
     * @return array{httpCode: int, body: ?string, error: bool}
     */
    private function httpGet(string $url): array
    {
        return $this->request('GET', $url, [
            'timeout' => 5,
        ]);
    }

    /**
     * @return array{httpCode: int, body: ?string, error: bool}
     */
    private function httpPost(string $url, array $jsonBody): array
    {
        return $this->request('POST', $url, [
            'json' => $jsonBody,
            'timeout' => 5,
        ]);
    }

    /**
     * @return array{httpCode: int, body: ?string, error: bool}
     */
    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options);

            return [
                'httpCode' => $response->getStatusCode(),
                'body' => $response->getContent(false),
                'error' => false,
            ];
        } catch (ExceptionInterface) {
            // Network failure (DNS, connection refused, timeout...): no HTTP response at all.
            return ['httpCode' => 0, 'body' => null, 'error' => true];
        }
    }
}
