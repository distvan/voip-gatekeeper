<?php

use App\Exceptions\ConfigurationException;
use Slim\Factory\AppFactory;
use App\Middleware\TelnyxSignatureMiddleware;
use App\Controllers\CallController;

require_once __DIR__ . '/../vendor/autoload.php';

$telnyxPublicKey = getenv('TELNYX_PUBLIC_KEY_BASE64');
$callForwardNumber = getenv('CALL_FORWARD_NUMBER');
$ttsVoice = getenv('TELNYX_TTS_VOICE');
$ttsLanguage = getenv('TELNYX_TTS_LANGUAGE');

if ($telnyxPublicKey === false || $telnyxPublicKey === '') {
    throw new ConfigurationException('TELNYX_PUBLIC_KEY_BASE64 is not configured.');
}

if ($callForwardNumber === false || $callForwardNumber === '') {
    throw new ConfigurationException('CALL_FORWARD_NUMBER is not configured.');
}

if (preg_match('/^\+[1-9]\d{6,14}$/', $callForwardNumber) !== 1) {
    throw new ConfigurationException('CALL_FORWARD_NUMBER must be a valid E.164 phone number.');
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
    $ttsLanguage !== false && $ttsLanguage !== '' ? $ttsLanguage : 'hu-HU'
);

$app->post('/incoming-call', [$callController, 'incomingCall']);
$app->post('/gather', [$callController, 'gather']);
$app->post('/reject', [$callController, 'reject']);

$app->run();
