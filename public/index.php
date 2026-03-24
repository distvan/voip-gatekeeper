<?php

use App\Exceptions\ConfigurationException;
use Slim\Factory\AppFactory;
use App\Middleware\TelnyxSignatureMiddleware;
use App\Controllers\CallController;

require_once __DIR__ . '/../vendor/autoload.php';

$telnyxPublicKey = getenv('TELNYX_PUBLIC_KEY_BASE64');
$callForwardNumber = getenv('CALL_FORWARD_NUMBER');

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

$callController = new CallController($callForwardNumber);

$app->post('/incoming-call', [$callController, 'incomingCall']);
$app->post('/gather', [$callController, 'gather']);
$app->post('/reject', [$callController, 'reject']);

$app->run();
