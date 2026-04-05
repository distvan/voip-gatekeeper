<?php

namespace Tests;

use App\Telephony\CallControlClientInterface;
use App\Telephony\CallControlDialOptions;
use App\Telephony\CallControlRecordingOptions;
use ArrayObject;

final class ApplicationFlowTest extends AppTestCase
{
    private const CALL_CONTROL_JSON_CONTENT_TYPE = 'application/json';
    private const CALL_CONTROL_WEBHOOK_PATH = '/call-control/incoming';
    private const CALLER_NUMBER = '+36301234567';
    private const INCOMING_CALL_PATH = '/incoming-call';
    private const INBOUND_DESTINATION_NUMBER = '+36111111111';
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
        self::assertStringContainsString('voice="Azure.hu-HU-NoemiNeural"', $body);
        self::assertStringNotContainsString('language="hu-HU"', $body);
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

    public function testIncomingCallForWhitelistedCallerDialsConfiguredNumberWithFallback(): void
    {
        $app = $this->createApp([
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_FORWARD_TIMEOUT_SECONDS' => '12',
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
        self::assertStringContainsString('<Number>+36201234567</Number>', $body);
    }

    public function testIncomingCallForWhitelistedCallerDialsSipDestinationWithFallback(): void
    {
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_FORWARD_TIMEOUT_SECONDS' => '12',
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

    public function testCallControlWebhookForWhitelistedCallerAnswersAndDialsSipDestination(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_TIMEOUT_SECONDS' => '12',
            'WHITELISTED_CALLERS' => self::CALLER_NUMBER,
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-123',
                'event_type' => 'call.initiated',
                'payload' => [
                    'call_control_id' => 'call-123',
                    'call_session_id' => 'session-123',
                    'connection_id' => 'conn-123',
                    'direction' => 'incoming',
                    'from' => self::CALLER_NUMBER,
                    'to' => self::INBOUND_DESTINATION_NUMBER,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::CALL_CONTROL_JSON_CONTENT_TYPE, $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('forwarding_started', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'answer',
                'callControlId' => 'call-123',
                'commandId' => 'evt-123-answer',
            ],
            [
                'action' => 'dial',
                'callControlId' => 'call-123',
                'destination' => self::SIP_DESTINATION,
                'from' => self::INBOUND_DESTINATION_NUMBER,
                'timeoutSeconds' => 12,
                'bridgeIntent' => true,
                'connectionId' => 'conn-123',
                'linkTo' => 'call-123',
                'bridgeOnAnswer' => true,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'call-123',
                    'inbound_call_session_id' => 'session-123',
                    'caller' => self::CALLER_NUMBER,
                    'flow' => 'direct_forward',
                    'dial_strategy' => 'auto_bridge',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-123-dial',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookForBridgingWhitelistedCallerDialsWithoutAnswer(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_TIMEOUT_SECONDS' => '12',
            'WHITELISTED_CALLERS' => self::CALLER_NUMBER,
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-123-bridging',
                'event_type' => 'call.initiated',
                'payload' => [
                    'call_control_id' => 'call-123-bridging',
                    'call_session_id' => 'session-123-bridging',
                    'connection_id' => 'conn-123-bridging',
                    'direction' => 'incoming',
                    'state' => 'bridging',
                    'from' => self::CALLER_NUMBER,
                    'to' => self::INBOUND_DESTINATION_NUMBER,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::CALL_CONTROL_JSON_CONTENT_TYPE, $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('forwarding_started', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'dial',
                'callControlId' => 'call-123-bridging',
                'destination' => self::SIP_DESTINATION,
                'from' => self::INBOUND_DESTINATION_NUMBER,
                'timeoutSeconds' => 12,
                'bridgeIntent' => true,
                'connectionId' => 'conn-123-bridging',
                'linkTo' => 'call-123-bridging',
                'bridgeOnAnswer' => true,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'call-123-bridging',
                    'inbound_call_session_id' => 'session-123-bridging',
                    'caller' => self::CALLER_NUMBER,
                    'flow' => 'direct_forward',
                    'dial_strategy' => 'auto_bridge',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-123-bridging-dial',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookForWhitelistedCallerWithFallbackUsesAmdManualBridge(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_FORWARD_TIMEOUT_SECONDS' => '12',
            'WHITELISTED_CALLERS' => self::CALLER_NUMBER,
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-123-amd',
                'event_type' => 'call.initiated',
                'payload' => [
                    'call_control_id' => 'call-123-amd',
                    'call_session_id' => 'session-123-amd',
                    'connection_id' => 'conn-123-amd',
                    'direction' => 'incoming',
                    'from' => self::CALLER_NUMBER,
                    'to' => self::INBOUND_DESTINATION_NUMBER,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('forwarding_started', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'answer',
                'callControlId' => 'call-123-amd',
                'commandId' => 'evt-123-amd-answer',
            ],
            [
                'action' => 'dial',
                'callControlId' => 'call-123-amd',
                'destination' => self::SIP_DESTINATION,
                'from' => self::INBOUND_DESTINATION_NUMBER,
                'timeoutSeconds' => 12,
                'bridgeIntent' => true,
                'connectionId' => 'conn-123-amd',
                'linkTo' => 'call-123-amd',
                'bridgeOnAnswer' => false,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'call-123-amd',
                    'inbound_call_session_id' => 'session-123-amd',
                    'caller' => self::CALLER_NUMBER,
                    'flow' => 'direct_forward',
                    'dial_strategy' => 'manual_bridge',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-123-amd-dial',
                'answeringMachineDetection' => 'detect',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookStartsVoicemailForNonWhitelistedCaller(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-456',
                'event_type' => 'call.initiated',
                'payload' => [
                    'call_control_id' => 'call-456',
                    'direction' => 'incoming',
                    'from' => self::CALLER_NUMBER,
                    'to' => self::INBOUND_DESTINATION_NUMBER,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('voicemail_prompt_started', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'answer',
                'callControlId' => 'call-456',
                'commandId' => 'evt-456-answer',
            ],
            [
                'action' => 'speak',
                'callControlId' => 'call-456',
                'payload' => 'Nyilatkozom, hogy marketing és reklám célú hívásokat nem fogadok. Az egyes gomb megnyomásával hangüzenetet hagyhat.',
                'voice' => 'Azure.hu-HU-NoemiNeural',
                'language' => null,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'call-456',
                    'inbound_call_session_id' => '',
                    'caller' => self::CALLER_NUMBER,
                    'flow' => 'voicemail',
                    'stage' => 'voicemail_prompt',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-456-voicemail-menu',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookStartsVoicemailForBridgingIncomingCallerWithoutAnswer(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-456-bridging',
                'event_type' => 'call.initiated',
                'payload' => [
                    'call_control_id' => 'call-456-bridging',
                    'direction' => 'incoming',
                    'state' => 'bridging',
                    'from' => self::CALLER_NUMBER,
                    'to' => self::INBOUND_DESTINATION_NUMBER,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('voicemail_prompt_started', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'speak',
                'callControlId' => 'call-456-bridging',
                'payload' => 'Nyilatkozom, hogy marketing és reklám célú hívásokat nem fogadok. Az egyes gomb megnyomásával hangüzenetet hagyhat.',
                'voice' => 'Azure.hu-HU-NoemiNeural',
                'language' => null,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'call-456-bridging',
                    'inbound_call_session_id' => '',
                    'caller' => self::CALLER_NUMBER,
                    'flow' => 'voicemail',
                    'stage' => 'voicemail_prompt',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-456-bridging-voicemail-menu',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookAcknowledgesAnsweredOutgoingLeg(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-answered',
                'event_type' => 'call.answered',
                'payload' => [
                    'call_control_id' => 'outbound-leg-1',
                    'direction' => 'outgoing',
                    'state' => 'answered',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-1',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('forwarding_answered', $this->readBody($response));
        self::assertSame([], $commands->getArrayCopy());
    }

    public function testCallControlWebhookWaitsForMachineDetectionBeforeManualBridge(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-answered-amd',
                'event_type' => 'call.answered',
                'payload' => [
                    'call_control_id' => 'outbound-leg-amd',
                    'direction' => 'outgoing',
                    'state' => 'answered',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-amd',
                        'flow' => 'direct_forward',
                        'dial_strategy' => 'manual_bridge',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('forwarding_waiting_for_machine_detection', $this->readBody($response));
        self::assertSame([], $commands->getArrayCopy());
    }

    public function testCallControlWebhookBridgesHumanAfterMachineDetection(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-machine-human',
                'event_type' => 'call.machine.detection.ended',
                'payload' => [
                    'call_control_id' => 'outbound-leg-human',
                    'direction' => 'outgoing',
                    'result' => 'human',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-human',
                        'flow' => 'direct_forward',
                        'dial_strategy' => 'manual_bridge',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('forwarding_bridging', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'bridge',
                'callControlId' => 'inbound-leg-human',
                'callControlIdToBridgeWith' => 'outbound-leg-human',
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'inbound-leg-human',
                    'flow' => 'direct_forward',
                    'dial_strategy' => 'manual_bridge',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-machine-human-bridge',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookStartsVoicemailAfterMachineDetection(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-machine-machine',
                'event_type' => 'call.machine.detection.ended',
                'payload' => [
                    'call_control_id' => 'outbound-leg-machine',
                    'direction' => 'outgoing',
                    'result' => 'machine',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-machine',
                        'flow' => 'direct_forward',
                        'dial_strategy' => 'manual_bridge',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('forwarding_machine_detected', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'hangup',
                'callControlId' => 'outbound-leg-machine',
                'commandId' => 'evt-machine-machine-outbound-hangup',
            ],
            [
                'action' => 'speak',
                'callControlId' => 'inbound-leg-machine',
                'payload' => 'A hívott fél jelenleg nem érhető el. Az egyes gomb megnyomásával hangüzenetet hagyhat.',
                'voice' => 'Azure.hu-HU-NoemiNeural',
                'language' => null,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'inbound-leg-machine',
                    'flow' => 'voicemail',
                    'dial_strategy' => 'manual_bridge',
                    'amd_result' => 'machine',
                    'stage' => 'voicemail_prompt',
                    'hangup_cause' => 'amd_machine',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-machine-machine-voicemail-menu',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookAcceptsCorrelatedHangupWithoutDirection(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-no-direction-hangup',
                'event_type' => 'call.hangup',
                'payload' => [
                    'call_control_id' => 'outbound-leg-3',
                    'hangup_cause' => 'user_busy',
                    'sip_hangup_cause' => '486',
                    'to' => self::SIP_DESTINATION,
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-3',
                        'flow' => 'direct_forward',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('forwarding_failed', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'hangup',
                'callControlId' => 'inbound-leg-3',
                'commandId' => 'evt-no-direction-hangup-cleanup',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookStartsVoicemailPromptAfterFailedOutgoingDial(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-hangup',
                'event_type' => 'call.hangup',
                'payload' => [
                    'call_control_id' => 'outbound-leg-2',
                    'direction' => 'outgoing',
                    'hangup_cause' => 'timeout',
                    'state' => 'hangup',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-2',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest(
            'POST',
            self::CALL_CONTROL_WEBHOOK_PATH,
            [],
            ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE],
            null,
            $payload === false ? '{}' : $payload
        );
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('voicemail_prompt_started', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'speak',
                'callControlId' => 'inbound-leg-2',
                'payload' => 'A hívott fél jelenleg nem érhető el. Az egyes gomb megnyomásával hangüzenetet hagyhat.',
                'voice' => 'Azure.hu-HU-NoemiNeural',
                'language' => null,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'inbound-leg-2',
                    'flow' => 'voicemail',
                    'stage' => 'voicemail_prompt',
                    'hangup_cause' => 'timeout',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-hangup-voicemail-menu',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookStartsGatherAfterVoicemailPromptEnds(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-speak-end',
                'event_type' => 'call.speak.ended',
                'payload' => [
                    'call_control_id' => 'inbound-leg-2',
                    'status' => 'completed',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-2',
                        'flow' => 'voicemail',
                        'stage' => 'voicemail_prompt',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest('POST', self::CALL_CONTROL_WEBHOOK_PATH, [], ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE], null, $payload === false ? '{}' : $payload);
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('voicemail_gather_started', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'gather',
                'callControlId' => 'inbound-leg-2',
                'validDigits' => '1',
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'inbound-leg-2',
                    'flow' => 'voicemail',
                    'stage' => 'voicemail_gather',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-speak-end-gather',
                'maximumDigits' => 1,
                'initialTimeoutMillis' => 5000,
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookStartsRecordingAfterVoicemailGatherConfirms(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt-gather-end',
                'event_type' => 'call.gather.ended',
                'payload' => [
                    'call_control_id' => 'inbound-leg-2',
                    'digits' => '1',
                    'status' => 'valid',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-2',
                        'flow' => 'voicemail',
                        'stage' => 'voicemail_gather',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $request = $this->createSignedRequest('POST', self::CALL_CONTROL_WEBHOOK_PATH, [], ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE], null, $payload === false ? '{}' : $payload);
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('voicemail_prompt_started', $this->readBody($response));
        self::assertSame([
            [
                'action' => 'speak',
                'callControlId' => 'inbound-leg-2',
                'payload' => 'Hagyjon hangüzenetet a sípszó után. A rögzítés néhány másodperc csend után automatikusan befejeződik.',
                'voice' => 'Azure.hu-HU-NoemiNeural',
                'language' => null,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'inbound-leg-2',
                    'flow' => 'voicemail',
                    'stage' => 'voicemail_recording_prompt',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-gather-end-recording-prompt',
            ],
        ], $commands->getArrayCopy());
    }

    public function testCallControlWebhookSavesVoicemailAndCompletesCall(): void
    {
        $commands = new ArrayObject();
        $client = $this->createFakeCallControlClient($commands);
        $app = $this->createApp([
            'CALL_FORWARD_DESTINATION_TYPE' => 'sip',
            'CALL_FORWARD_NUMBER' => false,
            'CALL_FORWARD_SIP_URI' => self::SIP_DESTINATION,
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
            'CALL_CONTROL_CLIENT' => $client,
        ]);

        $recordingPromptPayload = json_encode([
            'data' => [
                'id' => 'evt-recording-prompt-end',
                'event_type' => 'call.speak.ended',
                'payload' => [
                    'call_control_id' => 'inbound-leg-2',
                    'status' => 'completed',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-2',
                        'flow' => 'voicemail',
                        'stage' => 'voicemail_recording_prompt',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $recordingSavedPayload = json_encode([
            'data' => [
                'id' => 'evt-recording-saved',
                'event_type' => 'call.recording.saved',
                'payload' => [
                    'call_control_id' => 'inbound-leg-2',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-2',
                        'flow' => 'voicemail',
                        'stage' => 'voicemail_recording',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $completeSpeakEndedPayload = json_encode([
            'data' => [
                'id' => 'evt-complete-speak-end',
                'event_type' => 'call.speak.ended',
                'payload' => [
                    'call_control_id' => 'inbound-leg-2',
                    'status' => 'completed',
                    'client_state' => base64_encode(json_encode([
                        'version' => 1,
                        'inbound_call_control_id' => 'inbound-leg-2',
                        'flow' => 'voicemail',
                        'stage' => 'voicemail_complete',
                    ], JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $recordingPromptRequest = $this->createSignedRequest('POST', self::CALL_CONTROL_WEBHOOK_PATH, [], ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE], null, $recordingPromptPayload === false ? '{}' : $recordingPromptPayload);
        $recordingPromptResponse = $app->handle($recordingPromptRequest);
        self::assertSame(200, $recordingPromptResponse->getStatusCode());
        self::assertStringContainsString('voicemail_recording_started', $this->readBody($recordingPromptResponse));

        $recordingSavedRequest = $this->createSignedRequest('POST', self::CALL_CONTROL_WEBHOOK_PATH, [], ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE], null, $recordingSavedPayload === false ? '{}' : $recordingSavedPayload);
        $recordingSavedResponse = $app->handle($recordingSavedRequest);
        self::assertSame(200, $recordingSavedResponse->getStatusCode());
        self::assertStringContainsString('voicemail_prompt_started', $this->readBody($recordingSavedResponse));

        $completeSpeakEndedRequest = $this->createSignedRequest('POST', self::CALL_CONTROL_WEBHOOK_PATH, [], ['content-type' => self::CALL_CONTROL_JSON_CONTENT_TYPE], null, $completeSpeakEndedPayload === false ? '{}' : $completeSpeakEndedPayload);
        $completeSpeakEndedResponse = $app->handle($completeSpeakEndedRequest);
        self::assertSame(200, $completeSpeakEndedResponse->getStatusCode());
        self::assertStringContainsString('call_completed', $this->readBody($completeSpeakEndedResponse));

        self::assertSame([
            [
                'action' => 'record-start',
                'callControlId' => 'inbound-leg-2',
                'format' => 'mp3',
                'channels' => 'single',
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'inbound-leg-2',
                    'flow' => 'voicemail',
                    'stage' => 'voicemail_recording',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-recording-prompt-end-record-start',
                'playBeep' => true,
                'maxLength' => 120,
                'timeoutSeconds' => 5,
            ],
            [
                'action' => 'speak',
                'callControlId' => 'inbound-leg-2',
                'payload' => 'Köszönöm az üzenetet. Viszonthallásra.',
                'voice' => 'Azure.hu-HU-NoemiNeural',
                'language' => null,
                'clientState' => base64_encode(json_encode([
                    'version' => 1,
                    'inbound_call_control_id' => 'inbound-leg-2',
                    'flow' => 'voicemail',
                    'stage' => 'voicemail_complete',
                ], JSON_UNESCAPED_SLASHES)),
                'commandId' => 'evt-recording-saved-complete',
            ],
            [
                'action' => 'hangup',
                'callControlId' => 'inbound-leg-2',
                'commandId' => 'evt-complete-speak-end-hangup',
            ],
        ], $commands->getArrayCopy());
    }

    public function testAliceVoiceKeepsLanguageAttribute(): void
    {
        $app = $this->createApp([
            'TELNYX_TTS_VOICE' => 'alice',
            'TELNYX_TTS_LANGUAGE' => 'hu-HU',
        ]);

        $request = $this->createSignedRequest('POST', self::INCOMING_CALL_PATH, ['From' => self::CALLER_NUMBER]);
        $response = $app->handle($request);
        $body = $this->readBody($response);

        self::assertStringContainsString('voice="alice" language="hu-HU"', $body);
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
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
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
            'CALL_FORWARD_FALLBACK_TO_VOICEMAIL' => 'true',
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

    /**
     * @param ArrayObject<int, array<string, mixed>> $commands
     */
    private function createFakeCallControlClient(ArrayObject $commands): CallControlClientInterface
    {
        return new class($commands) implements CallControlClientInterface {
            /** @var ArrayObject<int, array<string, mixed>> */
            private ArrayObject $commands;

            /** @param ArrayObject<int, array<string, mixed>> $commands */
            public function __construct(ArrayObject $commands)
            {
                $this->commands = $commands;
            }

            public function answer(string $callControlId, ?string $commandId = null): void
            {
                $this->commands->append([
                    'action' => 'answer',
                    'callControlId' => $callControlId,
                    'commandId' => $commandId,
                ]);
            }

            public function bridge(
                string $callControlId,
                string $callControlIdToBridgeWith,
                ?string $clientState = null,
                ?string $commandId = null
            ): void {
                $this->commands->append([
                    'action' => 'bridge',
                    'callControlId' => $callControlId,
                    'callControlIdToBridgeWith' => $callControlIdToBridgeWith,
                    'clientState' => $clientState,
                    'commandId' => $commandId,
                ]);
            }

            public function speakText(
                string $callControlId,
                string $payload,
                string $voice,
                ?string $language = null,
                ?string $clientState = null,
                ?string $commandId = null
            ): void {
                $this->commands->append([
                    'action' => 'speak',
                    'callControlId' => $callControlId,
                    'payload' => $payload,
                    'voice' => $voice,
                    'language' => $language,
                    'clientState' => $clientState,
                    'commandId' => $commandId,
                ]);
            }

            public function gather(
                string $callControlId,
                string $validDigits = '123',
                ?string $clientState = null,
                ?string $commandId = null,
                int $maximumDigits = 1,
                int $initialTimeoutMillis = 5000
            ): void {
                $this->commands->append([
                    'action' => 'gather',
                    'callControlId' => $callControlId,
                    'validDigits' => $validDigits,
                    'clientState' => $clientState,
                    'commandId' => $commandId,
                    'maximumDigits' => $maximumDigits,
                    'initialTimeoutMillis' => $initialTimeoutMillis,
                ]);
            }

            public function startRecording(string $callControlId, ?CallControlRecordingOptions $options = null): void
            {
                $options ??= new CallControlRecordingOptions();

                $this->commands->append([
                    'action' => 'record-start',
                    'callControlId' => $callControlId,
                    'format' => $options->format,
                    'channels' => $options->channels,
                    'clientState' => $options->clientState,
                    'commandId' => $options->commandId,
                    'playBeep' => $options->playBeep,
                    'maxLength' => $options->maxLength,
                    'timeoutSeconds' => $options->timeoutSeconds,
                ]);
            }

            public function hangup(string $callControlId, ?string $commandId = null): void
            {
                $this->commands->append([
                    'action' => 'hangup',
                    'callControlId' => $callControlId,
                    'commandId' => $commandId,
                ]);
            }

            public function dial(
                string $callControlId,
                string $destination,
                string $from,
                ?CallControlDialOptions $options = null
            ): void {
                $options ??= new CallControlDialOptions();

                $this->commands->append([
                    'action' => 'dial',
                    'callControlId' => $callControlId,
                    'destination' => $destination,
                    'from' => $from,
                    'timeoutSeconds' => $options->timeoutSeconds,
                    'bridgeIntent' => $options->bridgeIntent,
                    'connectionId' => $options->connectionId,
                    'linkTo' => $options->linkTo,
                    'bridgeOnAnswer' => $options->bridgeOnAnswer,
                    'clientState' => $options->clientState,
                    'commandId' => $options->commandId,
                ] + ($options->answeringMachineDetection !== null ? [
                    'answeringMachineDetection' => $options->answeringMachineDetection,
                ] : []));
            }
        };
    }
}
