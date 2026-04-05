<?php

namespace App\Telephony;

use App\Exceptions\CallControlException;

final class CallControlContextSupport
{
    private const OUTBOUND_HANGUP_LOG_EVENT = 'call_control_outbound_hangup';

    /**
     * @return array<string, mixed>|null
     */
    public function decodeClientState(mixed $clientState): ?array
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
    public function encodeClientState(array $state): string
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
    public function logOutboundHangup(array $normalizedEvent, bool $hasClientState): void
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
