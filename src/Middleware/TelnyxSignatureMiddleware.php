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

        $this->logConfiguredPublicKeyFingerprint($decodedPublicKey);
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if ($this->shouldBypassSignatureValidation($request)) {
            return $handler->handle($request);
        }

        $signature = $request->getHeaderLine('telnyx-signature-ed25519');
        $timestamp = $request->getHeaderLine('telnyx-timestamp');
        $body = $this->getVerificationBody($request);

        $errorMessage = $this->getValidationError($signature, $timestamp, $body);

        if ($errorMessage !== null) {
            $this->logValidationFailure($request, $errorMessage, $signature, $timestamp, $body);
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
            $payload = $timestamp . '|' . $body;
            $decodedSignature = base64_decode($signature, true);

            if ($decodedSignature === false) {
                $errorMessage = 'Invalid signature encoding';
            } else {
                try {
                    $isValidSignature = sodium_crypto_sign_verify_detached($decodedSignature, $payload, $this->publicKey);
                } catch (\SodiumException) {
                    $isValidSignature = false;
                }

                if (!$isValidSignature) {
                    $errorMessage = 'Invalid signature';
                }
            }
        }

        return $errorMessage;
    }

    private function getVerificationBody(Request $request): string
    {
        $rawBody = file_get_contents('php://input');

        if (is_string($rawBody) && $rawBody !== '') {
            return $rawBody;
        }

        return (string) $request->getBody();
    }

    private function logValidationFailure(
        Request $request,
        string $errorMessage,
        string $signature,
        string $timestamp,
        string $body
    ): void {
        $ageSeconds = null;

        if (ctype_digit($timestamp)) {
            $ageSeconds = abs(time() - (int) $timestamp);
        }

        $serverSignature = $_SERVER['HTTP_TELNYX_SIGNATURE_ED25519'] ?? '';
        $serverTimestamp = $_SERVER['HTTP_TELNYX_TIMESTAMP'] ?? '';
        $phpInputBody = file_get_contents('php://input');

        if (!is_string($phpInputBody)) {
            $phpInputBody = '';
        }

        $psrBody = (string) $request->getBody();

        $context = [
            'event' => 'telnyx_signature_validation_failed',
            'reason' => $errorMessage,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'has_signature_header' => $signature !== '',
            'has_timestamp_header' => $timestamp !== '',
            'server_has_signature_header' => $serverSignature !== '',
            'server_has_timestamp_header' => $serverTimestamp !== '',
            'psr_server_signature_match' => $signature === $serverSignature,
            'psr_server_timestamp_match' => $timestamp === $serverTimestamp,
            'signature_length' => strlen($signature),
            'timestamp' => $timestamp !== '' ? $timestamp : null,
            'timestamp_age_seconds' => $ageSeconds,
            'body_length' => strlen($body),
            'body_sha256' => hash('sha256', $body),
            'php_input_length' => strlen($phpInputBody),
            'php_input_sha256' => hash('sha256', $phpInputBody),
            'psr_body_length' => strlen($psrBody),
            'psr_body_sha256' => hash('sha256', $psrBody),
            'content_type' => $request->getHeaderLine('content-type'),
            'user_agent' => $request->getHeaderLine('user-agent'),
        ];

        error_log((string) json_encode($context, JSON_UNESCAPED_SLASHES));
    }

    private function logConfiguredPublicKeyFingerprint(string $decodedPublicKey): void
    {
        $context = [
            'event' => 'telnyx_public_key_loaded',
            'public_key_length' => strlen($decodedPublicKey),
            'public_key_sha256' => hash('sha256', $decodedPublicKey),
        ];

        error_log((string) json_encode($context, JSON_UNESCAPED_SLASHES));
    }

    private function forbiddenResponse(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write($message);

        return $response->withStatus(403);
    }
}
