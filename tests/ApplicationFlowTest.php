<?php

namespace Tests;

final class ApplicationFlowTest extends AppTestCase
{
    private const CALLER_NUMBER = '+36301234567';
    private const INCOMING_CALL_PATH = '/incoming-call';
    private const SIP_DESTINATION = 'sip:desk@pbx.example.com';

    public function testIncomingCallForNonWhitelistedCallerPromptsForVoicemail(): void
    {
        $app = $this->createApp();
        $request = $this->createSignedRequest('POST', self::INCOMING_CALL_PATH, ['From' => self::CALLER_NUMBER]);
        $response = $app->handle($request);
        $body = $this->readBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/xml', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<Gather numDigits="1" action="/gather" timeout="5">', $body);
        self::assertStringContainsString('voice="alice" language="hu-HU"', $body);
        self::assertStringContainsString('<Redirect>/reject</Redirect>', $body);
    }

    public function testIncomingCallForWhitelistedCallerDialsConfiguredNumber(): void
    {
        $app = $this->createApp(['WHITELISTED_CALLERS' => self::CALLER_NUMBER]);
        $request = $this->createSignedRequest('POST', self::INCOMING_CALL_PATH, ['From' => self::CALLER_NUMBER]);
        $response = $app->handle($request);
        $body = $this->readBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<Dial callerId="' . self::CALLER_NUMBER . '" record="record-from-answer">', $body);
        self::assertStringContainsString('<Number>+36201234567</Number>', $body);
    }

    public function testIncomingCallForWhitelistedCallerDialsSipDestinationWithFallback(): void
    {
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_FORWARD_SIP_TIMEOUT_SECONDS' => '12',
            'WHITELISTED_CALLERS' => self::CALLER_NUMBER,
        ]);

        $request = $this->createSignedRequest('POST', self::INCOMING_CALL_PATH, ['From' => self::CALLER_NUMBER]);
        $response = $app->handle($request);
        $body = $this->readBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<Dial', $body);
        self::assertStringContainsString('action="/dial-fallback"', $body);
        self::assertStringContainsString('method="POST"', $body);
        self::assertStringContainsString('timeout="12"', $body);
        self::assertStringContainsString('answerOnBridge="true"', $body);
        self::assertStringContainsString('callerId="' . self::CALLER_NUMBER . '"', $body);
        self::assertStringContainsString('<Sip>' . self::SIP_DESTINATION . '</Sip>', $body);
    }

    public function testCustomVoiceOmitsLanguageAttribute(): void
    {
        $app = $this->createApp([
            'TELNYX_TTS_VOICE' => 'Azure.hu-HU-NoemiNeural',
            'TELNYX_TTS_LANGUAGE' => 'hu-HU',
        ]);

        $request = $this->createSignedRequest('POST', self::INCOMING_CALL_PATH, ['From' => self::CALLER_NUMBER]);
        $response = $app->handle($request);
        $body = $this->readBody($response);

        self::assertStringContainsString('voice="Azure.hu-HU-NoemiNeural"', $body);
        self::assertStringNotContainsString('language="hu-HU"', $body);
    }

    public function testGatherWithDigitOneStartsRecording(): void
    {
        $app = $this->createApp();
        $request = $this->createSignedRequest('POST', '/gather', ['Digits' => '1']);
        $response = $app->handle($request);
        $body = $this->readBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<Record action="/recording-complete" method="POST" maxLength="120" playBeep="true" finishOnKey="#" timeout="5" />', $body);
    }

    public function testGatherWithUnexpectedDigitRejectsCall(): void
    {
        $app = $this->createApp();
        $request = $this->createSignedRequest('POST', '/gather', ['Digits' => '9']);
        $response = $app->handle($request);
        $body = $this->readBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hibás választás.', $body);
        self::assertStringContainsString('<Hangup/>', $body);
    }

    public function testDialFallbackStartsVoicemailForNoAnswer(): void
    {
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL' => 'true',
        ]);

        $request = $this->createSignedRequest('POST', '/dial-fallback', ['DialCallStatus' => 'no-answer']);
        $response = $app->handle($request);
        $body = $this->readBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<Record action="/recording-complete" method="POST"', $body);
    }

    public function testDialFallbackHangsUpWhenStatusDoesNotTriggerVoicemail(): void
    {
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_SIP_FALLBACK_TO_VOICEMAIL' => 'true',
        ]);

        $request = $this->createSignedRequest('POST', '/dial-fallback', ['DialCallStatus' => 'completed']);
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<Response><Hangup/></Response>', $this->readBody($response));
    }

    public function testRecordingCompleteThanksCaller(): void
    {
        $app = $this->createApp();
        $request = $this->createSignedRequest('POST', '/recording-complete');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Köszönöm az üzenetet.', $this->readBody($response));
    }

    public function testRejectEndsCallWhenCallerDoesNotConfirm(): void
    {
        $app = $this->createApp();
        $request = $this->createSignedRequest('POST', '/reject');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Nem történt megerősítés.', $this->readBody($response));
    }

    public function testMissingSignatureHeadersReturnsForbidden(): void
    {
        $app = $this->createApp();
        $request = new \Nyholm\Psr7\ServerRequest('POST', self::INCOMING_CALL_PATH, [], 'From=%2B36301234567');
        $request = $request->withParsedBody(['From' => self::CALLER_NUMBER]);
        $response = $app->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Missing signature headers', $this->readBody($response));
    }

    public function testInvalidSignatureReturnsForbidden(): void
    {
        $app = $this->createApp();
        $request = $this->createSignedRequest(
            'POST',
            self::INCOMING_CALL_PATH,
            ['From' => self::CALLER_NUMBER],
            ['telnyx-signature-ed25519' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES))]
        );
        $response = $app->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Invalid signature', $this->readBody($response));
    }

    public function testStaleTimestampReturnsForbidden(): void
    {
        $app = $this->createApp();
        $request = $this->createSignedRequest(
            'POST',
            self::INCOMING_CALL_PATH,
            ['From' => self::CALLER_NUMBER],
            [],
            (string) (time() - 600)
        );
        $response = $app->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Stale webhook timestamp', $this->readBody($response));
    }
}
