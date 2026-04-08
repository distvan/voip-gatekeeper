<?php

namespace Tests;

use App\ApplicationFactory;
use App\Exceptions\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApplicationFactoryTest extends AppTestCase
{
    public function testHealthEndpointBypassesSignatureValidation(): void
    {
        $app = $this->createApp();
        $response = $app->handle($this->createUnsignedRequest('GET', '/health'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('ok', $this->readBody($response));
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testInvalidConfigurationIsRejected(array $overrides, string $expectedMessage): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->createApp($overrides);
    }

    /**
     * @return iterable<string, array{0: array<string, string|false>, 1: string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'missing public key' => [
            ['TELNYX_PUBLIC_KEY_BASE64' => false],
            'TELNYX_PUBLIC_KEY_BASE64 is not configured.',
        ];

        yield 'invalid destination type' => [
            ['CALL_FORWARD_DESTINATION_TYPE' => 'pstn'],
            'CALL_FORWARD_DESTINATION_TYPE must be either e164 or sip.',
        ];

        yield 'missing e164 number' => [
            ['CALL_FORWARD_NUMBER' => false],
            'CALL_FORWARD_NUMBER is not configured for e164 destination mode.',
        ];

        yield 'invalid whitelist' => [
            ['WHITELISTED_CALLERS' => '+36201234567,invalid'],
            'WHITELISTED_CALLERS must contain only valid E.164 phone numbers separated by commas.',
        ];

        yield 'invalid fallback timeout' => [
            ['CALL_FORWARD_TIMEOUT_SECONDS' => 'fast'],
            'CALL_FORWARD_TIMEOUT_SECONDS must be an integer number of seconds.',
        ];
    }

    public function testCreateAcceptsLegacyFallbackAliases(): void
    {
        $app = $this->createApp([
            'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_FORWARD_SIP_TIMEOUT_SECONDS' => '12',
            'WHITELISTED_CALLERS' => '+36301234567',
        ]);

        $response = $app->handle($this->createUnsignedRequest('GET', '/health'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreateAcceptsSecureSipUriDestination(): void
    {
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => 'sips:userinfo54018@sip.telnyx.com',
        ]);

        $response = $app->handle($this->createUnsignedRequest('GET', '/health'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreateFromEnvironmentBuildsApp(): void
    {
        putenv('TELNYX_PUBLIC_KEY_BASE64=' . $this->publicKeyBase64);
        putenv('CALL_FORWARD_DESTINATION_TYPE=e164');
        putenv('CALL_FORWARD_NUMBER=+36201234567');
        putenv('CALL_FORWARD_SIP_URI');
        putenv('CALL_FORWARD_FALLBACK_TO_VOICEMAIL');
        putenv('CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL');
        putenv('CALL_FORWARD_TIMEOUT_SECONDS');
        putenv('CALL_FORWARD_SIP_TIMEOUT_SECONDS');
        putenv('TELNYX_OUTBOUND_SIP_CONNECTION_ID');
        putenv('TELNYX_API_KEY');
        putenv('TELNYX_TTS_VOICE');
        putenv('TELNYX_TTS_LANGUAGE');
        putenv('WHITELISTED_CALLERS');

        $app = ApplicationFactory::createFromEnvironment();
        $response = $app->handle($this->createUnsignedRequest('GET', '/health'));

        self::assertSame(200, $response->getStatusCode());
    }
}
