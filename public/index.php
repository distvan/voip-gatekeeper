<?php

use App\Exceptions\ConfigurationException;
use App\Support\E164PhoneNumber;
use Slim\Factory\AppFactory;
use App\Middleware\TelnyxSignatureMiddleware;
use App\Controllers\CallController;

require_once __DIR__ . '/../vendor/autoload.php';

$telnyxPublicKey = getenv('TELNYX_PUBLIC_KEY_BASE64');
$callForwardNumber = getenv('CALL_FORWARD_NUMBER');
$ttsVoice = getenv('TELNYX_TTS_VOICE');
$ttsLanguage = getenv('TELNYX_TTS_LANGUAGE');
$whitelistedCallers = getenv('WHITELISTED_CALLERS');

if ($telnyxPublicKey === false || $telnyxPublicKey === '') {
    throw new ConfigurationException('TELNYX_PUBLIC_KEY_BASE64 is not configured.');
}

if ($callForwardNumber === false || $callForwardNumber === '') {
    throw new ConfigurationException('CALL_FORWARD_NUMBER is not configured.');
}

if (!E164PhoneNumber::isValid($callForwardNumber)) {
    throw new ConfigurationException('CALL_FORWARD_NUMBER must be a valid E.164 phone number.');
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
    $callForwardNumber,
    $ttsVoice !== false && $ttsVoice !== '' ? $ttsVoice : 'alice',
    $ttsLanguage !== false && $ttsLanguage !== '' ? $ttsLanguage : 'hu-HU',
    $parsedWhitelistedCallers
);

$app->post('/incoming-call', [$callController, 'incomingCall']);
$app->post('/gather', [$callController, 'gather']);
$app->post('/recording-complete', [$callController, 'recordingComplete']);
$app->post('/reject', [$callController, 'reject']);

$app->run();
