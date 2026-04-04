<?php

namespace App;

use App\Controllers\CallControlController;
use App\Controllers\CallController;
use App\Exceptions\ConfigurationException;
use App\Middleware\TelnyxSignatureMiddleware;
use App\Support\E164PhoneNumber;
use App\Support\SipUri;
use App\Telephony\CallControlClientInterface;
use App\Telephony\TelnyxCallControlClient;
use App\Telephony\CallControlWebhookHandler;
use App\Telephony\CallControlVoicemailFlow;
use InvalidArgumentException;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

final class ApplicationFactory
{
    /**
     * @return array<string, string|false>
     */
    private const ENV_KEYS = [
        'TELNYX_PUBLIC_KEY_BASE64',
        'CALL_FORWARD_DESTINATION_TYPE',
        'CALL_FORWARD_NUMBER',
        'CALL_FORWARD_SIP_URI',
        'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL',
        'CALL_FORWARD_SIP_TIMEOUT_SECONDS',
        'TELNYX_API_KEY',
        'TELNYX_TTS_VOICE',
        'TELNYX_TTS_LANGUAGE',
        'WHITELISTED_CALLERS',
    ];

    public static function createFromEnvironment(): App
    {
        $configuration = [];

        foreach (self::ENV_KEYS as $key) {
            $configuration[$key] = getenv($key);
        }

        return self::create($configuration);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function create(array $configuration): App
    {
        $telnyxPublicKey = self::getRequiredString($configuration, 'TELNYX_PUBLIC_KEY_BASE64');
        $callForwardDestinationType = trim(strtolower(self::getRequiredString($configuration, 'CALL_FORWARD_DESTINATION_TYPE')));

        if ($callForwardDestinationType !== 'e164' && $callForwardDestinationType !== 'sip') {
            throw new ConfigurationException('CALL_FORWARD_DESTINATION_TYPE must be either e164 or sip.');
        }

        $enableSipFallbackToVoicemail = self::parseSipFallbackFlag(
            self::getOptionalString($configuration, 'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL')
        );

        if ($enableSipFallbackToVoicemail && $callForwardDestinationType !== 'sip') {
            throw new ConfigurationException(
                'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL can be enabled only when CALL_FORWARD_DESTINATION_TYPE=sip.'
            );
        }

        $sipTimeoutSeconds = self::parseSipTimeoutSeconds(
            $callForwardDestinationType,
            self::getOptionalString($configuration, 'CALL_FORWARD_SIP_TIMEOUT_SECONDS')
        );

        $callForwardDestination = self::resolveForwardDestination($configuration, $callForwardDestinationType);
        $parsedWhitelistedCallers = self::parseWhitelistedCallers(
            self::getOptionalString($configuration, 'WHITELISTED_CALLERS')
        );
        $callControlClient = self::resolveCallControlClient($configuration);
        $ttsVoice = self::getOptionalString($configuration, 'TELNYX_TTS_VOICE');
        $ttsLanguage = self::getOptionalString($configuration, 'TELNYX_TTS_LANGUAGE');
        $resolvedTtsVoice = $ttsVoice !== false && $ttsVoice !== '' ? $ttsVoice : 'alice';
        $resolvedTtsLanguage = $ttsLanguage !== false && $ttsLanguage !== '' ? $ttsLanguage : 'hu-HU';

        $app = SlimAppFactory::create();
        $app->add(new TelnyxSignatureMiddleware($telnyxPublicKey));

        $app->get('/health', static function ($request, $response) {
            unset($request);

            $response->getBody()->write('ok');

            return $response->withHeader('Content-Type', 'text/plain');
        });

        $callController = new CallController(
            $callForwardDestinationType,
            $callForwardDestination,
            $enableSipFallbackToVoicemail,
            $sipTimeoutSeconds,
            $parsedWhitelistedCallers,
            [
                'voice' => $resolvedTtsVoice,
                'language' => $resolvedTtsLanguage,
            ]
        );
        $callControlController = new CallControlController(
            new CallControlWebhookHandler(
                $callForwardDestinationType,
                $callForwardDestination,
                $sipTimeoutSeconds,
                $parsedWhitelistedCallers,
                $callControlClient,
                $callControlClient === null ? null : new CallControlVoicemailFlow(
                    $callForwardDestinationType,
                    $enableSipFallbackToVoicemail,
                    $callControlClient,
                    $resolvedTtsVoice,
                    $resolvedTtsLanguage
                )
            )
        );

        $app->post('/incoming-call', [$callController, 'incomingCall']);
        $app->post('/call-control/incoming', [$callControlController, 'incomingWebhook']);
        $app->post('/dial-fallback', [$callController, 'dialFallback']);
        $app->post('/gather', [$callController, 'gather']);
        $app->post('/recording-complete', [$callController, 'recordingComplete']);
        $app->post('/reject', [$callController, 'reject']);

        return $app;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function getRequiredString(array $configuration, string $key): string
    {
        $value = self::getOptionalString($configuration, $key);

        if ($value === false || $value === '') {
            throw new ConfigurationException($key . ' is not configured.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function getOptionalString(array $configuration, string $key): string|false
    {
        $value = $configuration[$key] ?? false;

        if ($value === null || $value === false) {
            return false;
        }

        if (!is_string($value)) {
            throw new ConfigurationException($key . ' must be a string value.');
        }

        return $value;
    }

    private static function parseSipFallbackFlag(string|false $callForwardSipFallbackToVoicemail): bool
    {
        if ($callForwardSipFallbackToVoicemail === false || $callForwardSipFallbackToVoicemail === '') {
            return false;
        }

        $enableSipFallbackToVoicemail = filter_var(
            $callForwardSipFallbackToVoicemail,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if (!is_bool($enableSipFallbackToVoicemail)) {
            throw new ConfigurationException('CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL must be a boolean value.');
        }

        return $enableSipFallbackToVoicemail;
    }

    private static function parseSipTimeoutSeconds(
        string $callForwardDestinationType,
        string|false $callForwardSipTimeoutSeconds
    ): ?int {
        if ($callForwardSipTimeoutSeconds === false || $callForwardSipTimeoutSeconds === '') {
            return null;
        }

        if ($callForwardDestinationType !== 'sip') {
            throw new ConfigurationException(
                'CALL_FORWARD_SIP_TIMEOUT_SECONDS can be configured only when CALL_FORWARD_DESTINATION_TYPE=sip.'
            );
        }

        if (!ctype_digit($callForwardSipTimeoutSeconds)) {
            throw new ConfigurationException('CALL_FORWARD_SIP_TIMEOUT_SECONDS must be an integer number of seconds.');
        }

        $sipTimeoutSeconds = (int) $callForwardSipTimeoutSeconds;

        if ($sipTimeoutSeconds < 5 || $sipTimeoutSeconds > 120) {
            throw new ConfigurationException('CALL_FORWARD_SIP_TIMEOUT_SECONDS must be between 5 and 120 seconds.');
        }

        return $sipTimeoutSeconds;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function resolveForwardDestination(array $configuration, string $callForwardDestinationType): string
    {
        if ($callForwardDestinationType === 'e164') {
            $callForwardNumber = self::getOptionalString($configuration, 'CALL_FORWARD_NUMBER');

            if ($callForwardNumber === false || $callForwardNumber === '') {
                throw new ConfigurationException('CALL_FORWARD_NUMBER is not configured for e164 destination mode.');
            }

            if (!E164PhoneNumber::isValid($callForwardNumber)) {
                throw new ConfigurationException('CALL_FORWARD_NUMBER must be a valid E.164 phone number.');
            }

            return $callForwardNumber;
        }

        $callForwardSipUri = self::getOptionalString($configuration, 'CALL_FORWARD_SIP_URI');

        if ($callForwardSipUri === false || $callForwardSipUri === '') {
            throw new ConfigurationException('CALL_FORWARD_SIP_URI is not configured for sip destination mode.');
        }

        if (!SipUri::isValid($callForwardSipUri)) {
            throw new ConfigurationException('CALL_FORWARD_SIP_URI must be a valid SIP URI.');
        }

        return $callForwardSipUri;
    }

    /**
     * @return array<string, bool>
     */
    private static function parseWhitelistedCallers(string|false $whitelistedCallers): array
    {
        if ($whitelistedCallers === false || trim($whitelistedCallers) === '') {
            return [];
        }

        try {
            return E164PhoneNumber::parseCommaSeparatedList($whitelistedCallers);
        } catch (InvalidArgumentException) {
            throw new ConfigurationException('WHITELISTED_CALLERS must contain only valid E.164 phone numbers separated by commas.');
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function resolveCallControlClient(array $configuration): ?CallControlClientInterface
    {
        $configuredClient = $configuration['CALL_CONTROL_CLIENT'] ?? null;

        if ($configuredClient !== null) {
            if (!$configuredClient instanceof CallControlClientInterface) {
                throw new ConfigurationException('CALL_CONTROL_CLIENT must implement CallControlClientInterface.');
            }

            return $configuredClient;
        }

        $apiKey = self::getOptionalString($configuration, 'TELNYX_API_KEY');

        if ($apiKey === false || $apiKey === '') {
            return null;
        }

        return new TelnyxCallControlClient($apiKey);
    }
}
