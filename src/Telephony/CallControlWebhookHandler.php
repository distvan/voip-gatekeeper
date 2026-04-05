<?php

namespace App\Telephony;

use App\Exceptions\CallControlException;

final class CallControlWebhookHandler
{
    private const CALL_CONTROL_CONTEXT_VERSION = 1;
    private const AMD_MACHINE_DETECTED_STATUS = 'forwarding_machine_detected';
    private const AMD_WAITING_STATUS = 'forwarding_waiting_for_machine_detection';
    private const AMD_RESULT_HUMAN = 'human';
    private const AMD_STANDARD_MODE = 'detect';
    private const DIRECT_FORWARD_AUTO_BRIDGE_STRATEGY = 'auto_bridge';
    private const DIRECT_FORWARD_MANUAL_BRIDGE_STRATEGY = 'manual_bridge';
    private const FORWARDING_STARTED_STATUS = 'forwarding_started';
    private const FORWARDING_BRIDGING_STATUS = 'forwarding_bridging';
    private const INCOMING_ALREADY_ACTIVE_STATES = ['answered', 'bridged', 'bridging'];
    private const VOICEMAIL_FLOW_NOT_CONFIGURED_REASON = 'Voicemail flow is not configured';
    private const SUPPORTED_FAILURE_CAUSES = [
        'busy',
        'call_rejected',
        'canceled',
        'failed',
        'no_answer',
        'no-answer',
        'timeout',
        'user_busy',
    ];

    private string $forwardDestinationType;
    private string $forwardDestination;
    private ?int $sipTimeoutSeconds;
    /** @var array<string, bool> */
    private array $whitelistedCallers;
    private ?CallControlClientInterface $callControlClient;
    private ?CallControlVoicemailFlow $voicemailFlow;
    private CallControlContextSupport $contextSupport;

    /**
     * @param array<string, bool> $whitelistedCallers
     */
    public function __construct(
        string $forwardDestinationType,
        string $forwardDestination,
        ?int $sipTimeoutSeconds,
        array $whitelistedCallers,
        ?CallControlClientInterface $callControlClient,
        ?CallControlVoicemailFlow $voicemailFlow = null,
        ?CallControlContextSupport $contextSupport = null
    ) {
        $this->forwardDestinationType = $forwardDestinationType;
        $this->forwardDestination = $forwardDestination;
        $this->sipTimeoutSeconds = $sipTimeoutSeconds;
        $this->whitelistedCallers = $whitelistedCallers;
        $this->callControlClient = $callControlClient;
        $this->voicemailFlow = $voicemailFlow;
        $this->contextSupport = $contextSupport ?? new CallControlContextSupport();
    }

    /**
     * @return array{payload: array<string, mixed>, statusCode: int}
     */
    public function handleIncomingWebhook(string $body): array
    {
        $statusCode = 200;
        $payload = [];
        $event = $this->decodeJsonBody($body);

        if ($event === null) {
            $statusCode = 400;
            $payload = ['error' => 'Invalid JSON payload'];
        } else {
            $normalizedEvent = $this->normalizeCallControlEvent($event);

            if ($normalizedEvent === null) {
                $statusCode = 400;
                $payload = ['error' => 'Invalid Call Control payload'];
            } elseif (!$this->isSupportedCallControlEvent($normalizedEvent)) {
                $payload = ['status' => 'ignored', 'reason' => 'Unsupported event'];
            } elseif ($this->callControlClient === null) {
                $statusCode = 503;
                $payload = ['status' => 'misconfigured', 'reason' => 'Call Control client is not configured'];
            } else {
                $payload = $this->handleSupportedEvent($normalizedEvent);

                if (($payload['status'] ?? null) === 'error') {
                    $statusCode = 502;
                }
            }
        }

        return [
            'payload' => $payload,
            'statusCode' => $statusCode,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function normalizeCallControlEvent(array $event): ?array
    {
        $data = $event['data'] ?? null;
        $payload = is_array($data) ? ($data['payload'] ?? null) : null;

        if (!is_array($data) || !is_array($payload)) {
            return null;
        }

        return [
            'eventId' => is_string($data['id'] ?? null) ? trim($data['id']) : '',
            'eventType' => is_string($data['event_type'] ?? null) ? trim($data['event_type']) : '',
            'callControlId' => $this->getPayloadString($payload, 'call_control_id'),
            'callSessionId' => $this->getPayloadString($payload, 'call_session_id'),
            'connectionId' => $this->getPayloadString($payload, 'connection_id'),
            'direction' => $this->getPayloadString($payload, 'direction'),
            'from' => $this->getPayloadString($payload, 'from'),
            'to' => $this->getPayloadString($payload, 'to'),
            'state' => $this->getPayloadString($payload, 'state'),
            'hangupCause' => $this->getPayloadString($payload, 'hangup_cause'),
            'sipHangupCause' => $this->getPayloadString($payload, 'sip_hangup_cause'),
            'digits' => $this->getPayloadString($payload, 'digits'),
            'gatherStatus' => $this->getPayloadString($payload, 'status'),
            'result' => $this->getPayloadString($payload, 'result'),
            'clientState' => $this->contextSupport->decodeClientState($payload['client_state'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getPayloadString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     */
    private function isSupportedCallControlEvent(array $normalizedEvent): bool
    {
        $eventType = $normalizedEvent['eventType'] ?? '';
        $callControlId = $normalizedEvent['callControlId'] ?? '';
        $direction = $normalizedEvent['direction'] ?? '';
        $hasClientState = is_array($normalizedEvent['clientState'] ?? null);
        $isOutgoingLifecycleEvent = in_array(
            $eventType,
            ['call.answered', 'call.bridged', 'call.hangup', 'call.machine.detection.ended', 'call.machine.greeting.ended'],
            true
        );
        $isInboundFlowEvent = in_array($eventType, ['call.speak.ended', 'call.gather.ended', 'call.recording.saved'], true);

        return $callControlId !== ''
            && (
                ($eventType === 'call.initiated' && $direction === 'incoming')
                || ($isOutgoingLifecycleEvent && ($direction === 'outgoing' || $hasClientState))
                || $isInboundFlowEvent
            );
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    private function handleSupportedEvent(array $normalizedEvent): array
    {
        $eventType = (string) ($normalizedEvent['eventType'] ?? '');
        $payload = $this->handleHangupEvent($normalizedEvent);

        if ($eventType === 'call.initiated') {
            $payload = $this->handleIncomingInitiatedCall($normalizedEvent);
        } elseif ($eventType === 'call.answered') {
            $payload = $this->handleAnsweredEvent($normalizedEvent);
        } elseif ($eventType === 'call.bridged') {
            $payload = $this->handleBridgedEvent($normalizedEvent);
        } elseif ($eventType === 'call.machine.detection.ended') {
            $payload = $this->handleMachineDetectionEndedEvent($normalizedEvent);
        } elseif ($eventType === 'call.machine.greeting.ended') {
            $payload = $this->handleMachineGreetingEndedEvent($normalizedEvent);
        } elseif ($eventType === 'call.speak.ended') {
            $payload = $this->voicemailFlow !== null
                ? $this->voicemailFlow->handleSpeakEndedEvent($normalizedEvent)
                : ['status' => 'ignored', 'reason' => self::VOICEMAIL_FLOW_NOT_CONFIGURED_REASON];
        } elseif ($eventType === 'call.gather.ended') {
            $payload = $this->voicemailFlow !== null
                ? $this->voicemailFlow->handleGatherEndedEvent($normalizedEvent)
                : ['status' => 'ignored', 'reason' => self::VOICEMAIL_FLOW_NOT_CONFIGURED_REASON];
        } elseif ($eventType === 'call.recording.saved') {
            $payload = $this->voicemailFlow !== null
                ? $this->voicemailFlow->handleRecordingSavedEvent($normalizedEvent)
                : ['status' => 'ignored', 'reason' => self::VOICEMAIL_FLOW_NOT_CONFIGURED_REASON];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    private function handleIncomingInitiatedCall(array $normalizedEvent): array
    {
        $callerNumber = is_string($normalizedEvent['from'] ?? null) ? $normalizedEvent['from'] : '';
        $callControlId = (string) $normalizedEvent['callControlId'];
        $shouldAnswerIncomingLeg = !$this->isIncomingLegAlreadyActive($normalizedEvent);
        $eventId = is_string($normalizedEvent['eventId'] ?? null) && $normalizedEvent['eventId'] !== ''
            ? $normalizedEvent['eventId']
            : $callControlId;
        $callSessionId = is_string($normalizedEvent['callSessionId'] ?? null)
            ? $normalizedEvent['callSessionId']
            : '';

        if (!$this->isCallerWhitelisted($callerNumber)) {
            $payload = [
                'status' => 'ignored',
                'reason' => self::VOICEMAIL_FLOW_NOT_CONFIGURED_REASON,
            ];

            if ($this->voicemailFlow !== null) {
                $payload = $this->voicemailFlow->startPromptForIncomingCall(
                    $callControlId,
                    [
                        'version' => self::CALL_CONTROL_CONTEXT_VERSION,
                        'inbound_call_control_id' => $callControlId,
                        'inbound_call_session_id' => $callSessionId,
                        'caller' => $callerNumber,
                    ],
                    $eventId,
                    $shouldAnswerIncomingLeg
                );
            }

            return $payload;
        }

        $connectionId = is_string($normalizedEvent['connectionId'] ?? null)
            ? $normalizedEvent['connectionId']
            : '';
        $fromAddress = $this->getOutboundFromAddress(
            ['to' => $normalizedEvent['to'] ?? null],
            $callerNumber
        );
        $clientState = $this->contextSupport->encodeClientState([
            'version' => self::CALL_CONTROL_CONTEXT_VERSION,
            'inbound_call_control_id' => $callControlId,
            'inbound_call_session_id' => $callSessionId,
            'caller' => $callerNumber,
            'flow' => 'direct_forward',
            'dial_strategy' => $this->shouldUseManualBridgeFlow()
                ? self::DIRECT_FORWARD_MANUAL_BRIDGE_STRATEGY
                : self::DIRECT_FORWARD_AUTO_BRIDGE_STRATEGY,
        ]);

        try {
            if ($shouldAnswerIncomingLeg) {
                $this->callControlClient->answer($callControlId, $eventId . '-answer');
            }

            $this->callControlClient->dial(
                $callControlId,
                $this->forwardDestination,
                $fromAddress,
                new CallControlDialOptions(
                    timeoutSeconds: $this->sipTimeoutSeconds,
                    bridgeIntent: true,
                    connectionId: $connectionId,
                    linkTo: $callControlId,
                    bridgeOnAnswer: !$this->shouldUseManualBridgeFlow(),
                    answeringMachineDetection: $this->shouldUseManualBridgeFlow() ? self::AMD_STANDARD_MODE : null,
                    clientState: $clientState,
                    commandId: $eventId . '-dial'
                )
            );
        } catch (CallControlException $exception) {
            return ['status' => 'error', 'reason' => $exception->getMessage()];
        }

        return [
            'status' => self::FORWARDING_STARTED_STATUS,
            'destination_type' => $this->forwardDestinationType,
            'destination' => $this->forwardDestination,
        ];
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    private function handleAnsweredEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;

        if (!is_array($clientState)) {
            return [
                'status' => 'ignored',
                'reason' => 'Outbound answered event is missing correlation state',
            ];
        }

        if ($this->isManualBridgeClientState($clientState)) {
            return [
                'status' => self::AMD_WAITING_STATUS,
                'inbound_call_control_id' => $clientState['inbound_call_control_id'] ?? '',
            ];
        }

        return [
            'status' => 'forwarding_answered',
            'inbound_call_control_id' => $clientState['inbound_call_control_id'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    private function handleBridgedEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;

        if (!is_array($clientState)) {
            return [
                'status' => 'ignored',
                'reason' => 'Outbound bridged event is missing correlation state',
            ];
        }

        return [
            'status' => 'forwarding_bridged',
            'inbound_call_control_id' => $clientState['inbound_call_control_id'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    private function handleMachineDetectionEndedEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;
        $payload = [
            'status' => 'ignored',
            'reason' => 'Machine detection event is missing correlation state',
        ];

        if (is_array($clientState)) {
            if (!$this->isManualBridgeClientState($clientState)) {
                $payload = [
                    'status' => 'ignored',
                    'reason' => 'Machine detection event does not belong to manual bridge flow',
                ];
            } else {
                $result = strtolower(is_string($normalizedEvent['result'] ?? null) ? $normalizedEvent['result'] : '');
                $normalizedResult = $result === '' ? 'not_sure' : $result;
                $payload = $result === self::AMD_RESULT_HUMAN
                    ? $this->bridgeDetectedHumanCall($normalizedEvent, $clientState)
                    : $this->startVoicemailAfterMachineDetection(
                        $normalizedEvent,
                        $clientState,
                        $normalizedResult
                    );
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    private function handleMachineGreetingEndedEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;
        $payload = [
            'status' => 'ignored',
            'reason' => 'Machine greeting ended event is missing correlation state',
        ];

        if (is_array($clientState)) {
            if (!$this->isManualBridgeClientState($clientState)) {
                $payload = [
                    'status' => 'ignored',
                    'reason' => 'Machine greeting ended event does not belong to manual bridge flow',
                ];
            } else {
                $payload = $this->startVoicemailAfterMachineDetection(
                    $normalizedEvent,
                    $clientState,
                    strtolower(is_string($normalizedEvent['result'] ?? null) ? $normalizedEvent['result'] : 'ended')
                );
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    private function handleHangupEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;
        $hangupCause = strtolower(is_string($normalizedEvent['hangupCause'] ?? null) ? $normalizedEvent['hangupCause'] : '');
        $this->contextSupport->logOutboundHangup($normalizedEvent, is_array($clientState));
        $payload = [
            'status' => 'ignored',
            'reason' => 'Outbound hangup event is missing correlation state',
        ];

        if (is_array($clientState)) {
            $inboundCallControlId = is_string($clientState['inbound_call_control_id'] ?? null)
                ? trim($clientState['inbound_call_control_id'])
                : '';

            if ($inboundCallControlId === '') {
                $payload['reason'] = 'Outbound hangup event is missing inbound call control id';
            } elseif (!in_array($hangupCause, self::SUPPORTED_FAILURE_CAUSES, true)) {
                $payload = [
                    'status' => 'ignored',
                    'reason' => 'Hangup cause does not require cleanup',
                    'hangup_cause' => $hangupCause,
                ];
            } elseif ($this->voicemailFlow !== null && $this->voicemailFlow->shouldStartForFailedDial()) {
                $payload = $this->voicemailFlow->startPrompt(
                    $inboundCallControlId,
                    $clientState,
                    (string) ($normalizedEvent['eventId'] ?? $inboundCallControlId),
                    $hangupCause
                );
            } else {
                try {
                    $this->callControlClient->hangup(
                        $inboundCallControlId,
                        ((string) ($normalizedEvent['eventId'] ?? $inboundCallControlId)) . '-cleanup'
                    );
                    $payload = [
                        'status' => 'forwarding_failed',
                        'hangup_cause' => $hangupCause,
                        'inbound_call_control_id' => $inboundCallControlId,
                    ];
                } catch (CallControlException $exception) {
                    $payload = ['status' => 'error', 'reason' => $exception->getMessage()];
                }
            }
        }

        return $payload;
    }

    private function isCallerWhitelisted(string $callerNumber): bool
    {
        return $callerNumber !== '' && isset($this->whitelistedCallers[$callerNumber]);
    }

    /**
     * @param array<string, mixed> $clientState
     */
    private function isManualBridgeClientState(array $clientState): bool
    {
        return ($clientState['flow'] ?? '') === 'direct_forward'
            && ($clientState['dial_strategy'] ?? '') === self::DIRECT_FORWARD_MANUAL_BRIDGE_STRATEGY;
    }

    private function shouldUseManualBridgeFlow(): bool
    {
        return $this->voicemailFlow !== null && $this->voicemailFlow->shouldStartForFailedDial();
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     */
    private function isIncomingLegAlreadyActive(array $normalizedEvent): bool
    {
        $state = strtolower(is_string($normalizedEvent['state'] ?? null) ? trim($normalizedEvent['state']) : '');

        return in_array($state, self::INCOMING_ALREADY_ACTIVE_STATES, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getOutboundFromAddress(array $payload, string $fallback): string
    {
        $to = $payload['to'] ?? null;

        if (is_string($to) && trim($to) !== '') {
            return trim($to);
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @param array<string, mixed> $clientState
     * @return array<string, mixed>
     */
    private function bridgeDetectedHumanCall(array $normalizedEvent, array $clientState): array
    {
        $inboundCallControlId = is_string($clientState['inbound_call_control_id'] ?? null)
            ? trim($clientState['inbound_call_control_id'])
            : '';

        if ($inboundCallControlId === '') {
            return [
                'status' => 'ignored',
                'reason' => 'Machine detection event is missing inbound call control id',
            ];
        }

        try {
            $this->callControlClient->bridge(
                $inboundCallControlId,
                (string) ($normalizedEvent['callControlId'] ?? ''),
                $this->contextSupport->encodeClientState($clientState),
                ((string) ($normalizedEvent['eventId'] ?? $inboundCallControlId)) . '-bridge'
            );
        } catch (CallControlException $exception) {
            return ['status' => 'error', 'reason' => $exception->getMessage()];
        }

        return [
            'status' => self::FORWARDING_BRIDGING_STATUS,
            'inbound_call_control_id' => $inboundCallControlId,
        ];
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @param array<string, mixed> $clientState
     * @return array<string, mixed>
     */
    private function startVoicemailAfterMachineDetection(array $normalizedEvent, array $clientState, string $result): array
    {
        if ($this->voicemailFlow === null) {
            return ['status' => 'ignored', 'reason' => self::VOICEMAIL_FLOW_NOT_CONFIGURED_REASON];
        }

        $inboundCallControlId = is_string($clientState['inbound_call_control_id'] ?? null)
            ? trim($clientState['inbound_call_control_id'])
            : '';

        if ($inboundCallControlId === '') {
            return [
                'status' => 'ignored',
                'reason' => 'Machine detection event is missing inbound call control id',
            ];
        }

        $eventId = (string) (($normalizedEvent['eventId'] ?? '') ?: $inboundCallControlId);

        try {
            $this->callControlClient->hangup(
                (string) ($normalizedEvent['callControlId'] ?? ''),
                $eventId . '-outbound-hangup'
            );
        } catch (CallControlException $exception) {
            $logLine = json_encode([
                'event' => 'call_control_outbound_hangup_cleanup_failed',
                'call_control_id' => (string) ($normalizedEvent['callControlId'] ?? ''),
                'reason' => $exception->getMessage(),
            ], JSON_UNESCAPED_SLASHES);

            if (is_string($logLine) && $logLine !== '') {
                error_log($logLine);
            }
        }

        $clientState['amd_result'] = $result;

        $payload = $this->voicemailFlow->startPrompt(
            $inboundCallControlId,
            $clientState,
            $eventId,
            'amd_' . $result
        );

        $payload['status'] = self::AMD_MACHINE_DETECTED_STATUS;
        $payload['amd_result'] = $result;

        return $payload;
    }

}
