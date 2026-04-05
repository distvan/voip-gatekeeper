<?php

namespace App\Telephony;

use App\Exceptions\CallControlException;

final class CallControlWebhookHandler
{
    private const CALL_CONTROL_CONTEXT_VERSION = 1;
    private const FORWARDING_STARTED_STATUS = 'forwarding_started';
    private const INCOMING_ALREADY_ACTIVE_STATES = ['answered', 'bridged', 'bridging'];
    private const VOICEMAIL_FLOW_NOT_CONFIGURED_REASON = 'Voicemail flow is not configured';
    private const OUTBOUND_HANGUP_LOG_EVENT = 'call_control_outbound_hangup';
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

    /**
     * @param array<string, bool> $whitelistedCallers
     */
    public function __construct(
        string $forwardDestinationType,
        string $forwardDestination,
        ?int $sipTimeoutSeconds,
        array $whitelistedCallers,
        ?CallControlClientInterface $callControlClient,
        ?CallControlVoicemailFlow $voicemailFlow = null
    ) {
        $this->forwardDestinationType = $forwardDestinationType;
        $this->forwardDestination = $forwardDestination;
        $this->sipTimeoutSeconds = $sipTimeoutSeconds;
        $this->whitelistedCallers = $whitelistedCallers;
        $this->callControlClient = $callControlClient;
        $this->voicemailFlow = $voicemailFlow;
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
            'clientState' => $this->decodeClientState($payload['client_state'] ?? null),
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
        $isOutgoingLifecycleEvent = in_array($eventType, ['call.answered', 'call.bridged', 'call.hangup'], true);
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
        $clientState = $this->encodeClientState([
            'version' => self::CALL_CONTROL_CONTEXT_VERSION,
            'inbound_call_control_id' => $callControlId,
            'inbound_call_session_id' => $callSessionId,
            'caller' => $callerNumber,
            'flow' => 'direct_forward',
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
                    bridgeOnAnswer: true,
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
    private function handleHangupEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;
        $hangupCause = strtolower(is_string($normalizedEvent['hangupCause'] ?? null) ? $normalizedEvent['hangupCause'] : '');
        $this->logOutboundHangup($normalizedEvent, is_array($clientState));
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
     * @return array<string, mixed>|null
     */
    private function decodeClientState(mixed $clientState): ?array
    {
        if (!is_string($clientState) || trim($clientState) === '') {
            return null;
        }

        $decoded = base64_decode($clientState, true);

        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        $parsed = json_decode($decoded, true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function encodeClientState(array $state): string
    {
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded) || $encoded === '') {
            throw new CallControlException('Failed to encode Call Control client state.');
        }

        return base64_encode($encoded);
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     */
    private function logOutboundHangup(array $normalizedEvent, bool $hasClientState): void
    {
        if (($normalizedEvent['eventType'] ?? '') !== 'call.hangup') {
            return;
        }

        $logLine = json_encode([
            'event' => self::OUTBOUND_HANGUP_LOG_EVENT,
            'call_control_id' => $normalizedEvent['callControlId'] ?? '',
            'call_session_id' => $normalizedEvent['callSessionId'] ?? '',
            'connection_id' => $normalizedEvent['connectionId'] ?? '',
            'direction' => $normalizedEvent['direction'] ?? '',
            'from' => $normalizedEvent['from'] ?? '',
            'to' => $normalizedEvent['to'] ?? '',
            'state' => $normalizedEvent['state'] ?? '',
            'hangup_cause' => $normalizedEvent['hangupCause'] ?? '',
            'sip_hangup_cause' => $normalizedEvent['sipHangupCause'] ?? '',
            'has_client_state' => $hasClientState,
        ], JSON_UNESCAPED_SLASHES);

        if (is_string($logLine) && $logLine !== '') {
            error_log($logLine);
        }
    }
}
