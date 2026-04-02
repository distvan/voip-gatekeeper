<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Slim\Psr7\Response as SlimResponse;
use InvalidArgumentException;

class TelnyxSignatureMiddleware implements Middleware
{
    private const HEALTH_CHECK_PATHS = ['/health'];

    private string $publicKey;
    private int $maxTimestampAge;

    public function __construct(string $publicKeyBase64, int $maxTimestampAge = 300)
    {
        $decodedPublicKey = base64_decode($publicKeyBase64, true);

        if ($decodedPublicKey === false) {
            throw new InvalidArgumentException('TELNYX_PUBLIC_KEY_BASE64 must be valid base64.');
        }

        $this->publicKey = $decodedPublicKey;
        $this->maxTimestampAge = $maxTimestampAge;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if ($this->shouldBypassSignatureValidation($request)) {
            return $handler->handle($request);
        }

        $signature = $request->getHeaderLine('telnyx-signature-ed25519');
        $timestamp = $request->getHeaderLine('telnyx-timestamp');
        $body = (string) $request->getBody();

        $errorMessage = $this->getValidationError($signature, $timestamp, $body);

        if ($errorMessage !== null) {
            return $this->forbiddenResponse($errorMessage);
        }

        return $handler->handle($request);
    }

    private function shouldBypassSignatureValidation(Request $request): bool
    {
        return $request->getMethod() === 'GET'
            && in_array($request->getUri()->getPath(), self::HEALTH_CHECK_PATHS, true);
    }

    private function getValidationError(string $signature, string $timestamp, string $body): ?string
    {
        $errorMessage = null;

        if ($signature === '' || $timestamp === '') {
            $errorMessage = 'Missing signature headers';
        } elseif (!ctype_digit($timestamp)) {
            $errorMessage = 'Invalid timestamp';
        } elseif (abs(time() - (int) $timestamp) > $this->maxTimestampAge) {
            $errorMessage = 'Stale webhook timestamp';
        } else {
            $payload = $timestamp  . '.' . $body;
            $decodedSignature = base64_decode($signature, true);

            if ($decodedSignature === false) {
                $errorMessage = 'Invalid signature encoding';
            } elseif (!sodium_crypto_sign_verify_detached($decodedSignature, $payload, $this->publicKey)) {
                $errorMessage = 'Invalid signature';
            }
        }

        return $errorMessage;
    }

    private function forbiddenResponse(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write($message);

        return $response->withStatus(403);
    }
}
