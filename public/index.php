<?php

use App\Exceptions\ConfigurationException;
use App\Support\E164PhoneNumber;
use App\Support\SipUri;
use Slim\Factory\AppFactory;
use App\Middleware\TelnyxSignatureMiddleware;
use App\Controllers\CallController;

require_once __DIR__ . '/../vendor/autoload.php';

$telnyxPublicKey = getenv('TELNYX_PUBLIC_KEY_BASE64');
$callForwardDestinationType = getenv('CALL_FORWARD_DESTINATION_TYPE');
$callForwardNumber = getenv('CALL_FORWARD_NUMBER');
$callForwardSipUri = getenv('CALL_FORWARD_SIP_URI');
$callForwardSipFallbackToVoicemail = getenv('CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL');
$callForwardSipTimeoutSeconds = getenv('CALL_FORWARD_SIP_TIMEOUT_SECONDS');
$ttsVoice = getenv('TELNYX_TTS_VOICE');
$ttsLanguage = getenv('TELNYX_TTS_LANGUAGE');
$whitelistedCallers = getenv('WHITELISTED_CALLERS');

if ($telnyxPublicKey === false || $telnyxPublicKey === '') {
    throw new ConfigurationException('TELNYX_PUBLIC_KEY_BASE64 is not configured.');
}

if ($callForwardDestinationType === false || $callForwardDestinationType === '') {
    throw new ConfigurationException('CALL_FORWARD_DESTINATION_TYPE is not configured.');
}

$callForwardDestinationType = trim(strtolower($callForwardDestinationType));

if ($callForwardDestinationType !== 'e164' && $callForwardDestinationType !== 'sip') {
    throw new ConfigurationException('CALL_FORWARD_DESTINATION_TYPE must be either e164 or sip.');
}

$enableSipFallbackToVoicemail = false;
$sipTimeoutSeconds = null;

if ($callForwardSipFallbackToVoicemail !== false && $callForwardSipFallbackToVoicemail !== '') {
    $enableSipFallbackToVoicemail = filter_var(
        $callForwardSipFallbackToVoicemail,
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    );

    if (!is_bool($enableSipFallbackToVoicemail)) {
        throw new ConfigurationException('CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL must be a boolean value.');
    }
}

if ($enableSipFallbackToVoicemail && $callForwardDestinationType !== 'sip') {
    throw new ConfigurationException(
        'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL can be enabled only when CALL_FORWARD_DESTINATION_TYPE=sip.'
    );
}

if ($callForwardSipTimeoutSeconds !== false && $callForwardSipTimeoutSeconds !== '') {
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
}

if ($callForwardDestinationType === 'e164') {
    if ($callForwardNumber === false || $callForwardNumber === '') {
        throw new ConfigurationException('CALL_FORWARD_NUMBER is not configured for e164 destination mode.');
    }

    if (!E164PhoneNumber::isValid($callForwardNumber)) {
        throw new ConfigurationException('CALL_FORWARD_NUMBER must be a valid E.164 phone number.');
    }

    $callForwardDestination = $callForwardNumber;
} else {
    if ($callForwardSipUri === false || $callForwardSipUri === '') {
        throw new ConfigurationException('CALL_FORWARD_SIP_URI is not configured for sip destination mode.');
    }

    if (!SipUri::isValid($callForwardSipUri)) {
        throw new ConfigurationException('CALL_FORWARD_SIP_URI must be a valid SIP URI.');
    }

    $callForwardDestination = $callForwardSipUri;
}

$parsedWhitelistedCallers = [];

if ($whitelistedCallers !== false && trim($whitelistedCallers) !== '') {
    try {
        $parsedWhitelistedCallers = E164PhoneNumber::parseCommaSeparatedList($whitelistedCallers);
    } catch (InvalidArgumentException) {
        throw new ConfigurationException('WHITELISTED_CALLERS must contain only valid E.164 phone numbers separated by commas.');
    }
}

$app = AppFactory::create();
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
    $ttsVoice !== false && $ttsVoice !== '' ? $ttsVoice : 'alice',
    $ttsLanguage !== false && $ttsLanguage !== '' ? $ttsLanguage : 'hu-HU',
    $parsedWhitelistedCallers
);

$app->post('/incoming-call', [$callController, 'incomingCall']);
$app->post('/dial-fallback', [$callController, 'dialFallback']);
$app->post('/gather', [$callController, 'gather']);
$app->post('/recording-complete', [$callController, 'recordingComplete']);
$app->post('/reject', [$callController, 'reject']);

$app->run();
