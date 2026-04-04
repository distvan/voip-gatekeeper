<?php

namespace Tests;

use App\ApplicationFactory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

abstract class AppTestCase extends TestCase
{
    private string $signingPrivateKey;
    protected string $publicKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = sodium_crypto_sign_keypair();
        $this->signingPrivateKey = sodium_crypto_sign_secretkey($keyPair);
        $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($keyPair));
    }

    /**
     * @param array<string, string|false> $overrides
     */
    protected function createApp(array $overrides = []): App
    {
        return ApplicationFactory::create(array_merge($this->defaultConfiguration(), $overrides));
    }

    /**
     * @param array<string, string> $parsedBody
     * @param array<string, string> $headers
     */
    protected function createSignedRequest(
        string $method,
        string $path,
        array $parsedBody = [],
        array $headers = [],
        ?string $timestamp = null,
        ?string $rawBody = null
    ): ServerRequest {
        $body = $rawBody ?? http_build_query($parsedBody, '', '&', PHP_QUERY_RFC3986);
        $requestTimestamp = $timestamp ?? (string) time();
        $signature = base64_encode(
            sodium_crypto_sign_detached($requestTimestamp . '|' . $body, $this->signingPrivateKey)
        );

        $request = new ServerRequest(
            $method,
            $path,
            array_merge(
                [
                    'content-type' => 'application/x-www-form-urlencoded',
                    'telnyx-signature-ed25519' => $signature,
                    'telnyx-timestamp' => $requestTimestamp,
                ],
                $headers
            ),
            $body
        );

        return $request->withParsedBody($parsedBody);
    }

    protected function createUnsignedRequest(string $method, string $path): ServerRequest
    {
        return new ServerRequest($method, $path);
    }

    protected function readBody(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    /**
     * @return array<string, string|false>
     */
    private function defaultConfiguration(): array
    {
        return [
            'TELNYX_PUBLIC_KEY_BASE64' => $this->publicKeyBase64,
            'CALL_FORWARD_DESTINATION_TYPE' => 'e164',
            'CALL_FORWARD_NUMBER' => '+36201234567',
            'CALL_FORWARD_SIP_URI' => false,
            'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL' => false,
            'CALL_FORWARD_SIP_TIMEOUT_SECONDS' => false,
            'TELNYX_API_KEY' => false,
            'TELNYX_TTS_VOICE' => false,
            'TELNYX_TTS_LANGUAGE' => false,
            'WHITELISTED_CALLERS' => false,
        ];
    }
}