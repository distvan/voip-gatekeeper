<?php

namespace App\Controllers;

use App\Telephony\CallControlWebhookHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CallControlController
{
    private const JSON_CONTENT_TYPE = 'application/json';

    private CallControlWebhookHandler $handler;

    public function __construct(CallControlWebhookHandler $handler)
    {
        $this->handler = $handler;
    }

    public function incomingWebhook(Request $request, Response $response): Response
    {
        $result = $this->handler->handleIncomingWebhook((string) $request->getBody());
        $response->getBody()->write((string) json_encode($result['payload'], JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', self::JSON_CONTENT_TYPE)
            ->withStatus($result['statusCode']);
    }
}
