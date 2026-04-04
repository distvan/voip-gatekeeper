<?php

namespace App\Telephony;

use App\Exceptions\CallControlException;

final class CallControlVoicemailFlow
{
    private const VOICEMAIL_MAX_LENGTH_SECONDS = 120;
    private const VOICEMAIL_INITIAL_TIMEOUT_MILLIS = 5000;
    private const VOICEMAIL_PROMPT_STAGE = 'voicemail_prompt';
    private const VOICEMAIL_GATHER_STAGE = 'voicemail_gather';
    private const VOICEMAIL_RECORDING_PROMPT_STAGE = 'voicemail_recording_prompt';
    private const VOICEMAIL_RECORDING_STAGE = 'voicemail_recording';
    private const VOICEMAIL_COMPLETE_STAGE = 'voicemail_complete';
    private const REJECT_STAGE = 'reject';

    public function __construct(
        private readonly string $forwardDestinationType,
        private readonly bool $enableSipFallbackToVoicemail,
        private readonly CallControlClientInterface $callControlClient,
        private readonly string $sayVoice = 'alice',
        private readonly string $sayLanguage = 'hu-HU'
    ) {
    }

    /**
     * @param array<string, mixed> $clientState
     * @return array<string, mixed>
     */
    public function startPrompt(string $callControlId, array $clientState, string $eventId, string $hangupCause): array
    {
        $state = $this->withStage($clientState, self::VOICEMAIL_PROMPT_STAGE);
        $state['hangup_cause'] = $hangupCause;

        return $this->speakText(
            $callControlId,
            'A hívott SIP végpont nem érhető el. Az egyes gomb megnyomásával hangüzenetet hagyhat.',
            $state,
            $eventId . '-voicemail-menu'
        );
    }

    /**
     * @param array<string, mixed> $clientState
     * @return array<string, mixed>
     */
    public function startPromptForIncomingCall(string $callControlId, array $clientState, string $eventId): array
    {
        try {
            $this->callControlClient->answer($callControlId, $eventId . '-answer');
        } catch (CallControlException $exception) {
            return ['status' => 'error', 'reason' => $exception->getMessage()];
        }

        return $this->speakText(
            $callControlId,
            'Nyilatkozom, hogy marketing és reklám célú hívásokat nem fogadok. Az egyes gomb megnyomásával hangüzenetet hagyhat.',
            $this->withStage($clientState, self::VOICEMAIL_PROMPT_STAGE),
            $eventId . '-voicemail-menu'
        );
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    public function handleSpeakEndedEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;
        $payload = ['status' => 'ignored', 'reason' => 'Speak ended event is missing correlation state'];

        if (is_array($clientState)) {
            $stage = is_string($clientState['stage'] ?? null) ? $clientState['stage'] : '';
            $callControlId = (string) ($normalizedEvent['callControlId'] ?? '');
            $eventId = (string) (($normalizedEvent['eventId'] ?? '') ?: $callControlId);

            if ($stage === self::VOICEMAIL_PROMPT_STAGE) {
                $payload = $this->startVoicemailGather($callControlId, $clientState, $eventId);
            } elseif ($stage === self::VOICEMAIL_RECORDING_PROMPT_STAGE) {
                $payload = $this->startVoicemailRecording($callControlId, $clientState, $eventId);
            } elseif ($stage === self::VOICEMAIL_COMPLETE_STAGE || $stage === self::REJECT_STAGE) {
                $payload = $this->hangupCall($callControlId, $eventId . '-hangup');
            } else {
                $payload = ['status' => 'ignored', 'reason' => 'Speak ended event stage is not handled'];
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    public function handleGatherEndedEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;
        $payload = ['status' => 'ignored', 'reason' => 'Gather ended event is missing correlation state'];

        if (is_array($clientState)) {
            $stage = is_string($clientState['stage'] ?? null) ? $clientState['stage'] : '';

            if ($stage !== self::VOICEMAIL_GATHER_STAGE) {
                $payload = ['status' => 'ignored', 'reason' => 'Gather ended event stage is not handled'];
            } else {
                $callControlId = (string) ($normalizedEvent['callControlId'] ?? '');
                $eventId = (string) (($normalizedEvent['eventId'] ?? '') ?: $callControlId);
                $digits = (string) ($normalizedEvent['digits'] ?? '');
                $gatherStatus = strtolower((string) ($normalizedEvent['gatherStatus'] ?? ''));

                if ($digits === '1' && ($gatherStatus === 'valid' || $gatherStatus === '')) {
                    $payload = $this->speakText(
                        $callControlId,
                        'Hagyjon hangüzenetet a sípszó után. A rögzítés néhány másodperc csend után automatikusan befejeződik.',
                        $this->withStage($clientState, self::VOICEMAIL_RECORDING_PROMPT_STAGE),
                        $eventId . '-recording-prompt'
                    );
                } else {
                    $payload = $this->speakText(
                        $callControlId,
                        'Nem történt megerősítés. Viszonthallásra.',
                        $this->withStage($clientState, self::REJECT_STAGE),
                        $eventId . '-reject'
                    );
                }
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $normalizedEvent
     * @return array<string, mixed>
     */
    public function handleRecordingSavedEvent(array $normalizedEvent): array
    {
        $clientState = $normalizedEvent['clientState'] ?? null;

        if (!is_array($clientState)) {
            return ['status' => 'ignored', 'reason' => 'Recording saved event is missing correlation state'];
        }

        $stage = is_string($clientState['stage'] ?? null) ? $clientState['stage'] : '';

        if ($stage !== self::VOICEMAIL_RECORDING_STAGE) {
            return ['status' => 'ignored', 'reason' => 'Recording saved event stage is not handled'];
        }

        return $this->speakText(
            (string) ($normalizedEvent['callControlId'] ?? ''),
            'Köszönöm az üzenetet. Viszonthallásra.',
            $this->withStage($clientState, self::VOICEMAIL_COMPLETE_STAGE),
            (string) (($normalizedEvent['eventId'] ?? '') ?: 'recording-saved') . '-complete'
        );
    }

    public function shouldStartForFailedDial(): bool
    {
        return $this->enableSipFallbackToVoicemail && $this->forwardDestinationType === 'sip';
    }

    /**
     * @param array<string, mixed> $clientState
     * @return array<string, mixed>
     */
    private function startVoicemailGather(string $callControlId, array $clientState, string $eventId): array
    {
        $state = $this->withStage($clientState, self::VOICEMAIL_GATHER_STAGE);

        try {
            $this->callControlClient->gather(
                $callControlId,
                '1',
                $this->encodeClientState($state),
                $eventId . '-gather',
                1,
                self::VOICEMAIL_INITIAL_TIMEOUT_MILLIS
            );
        } catch (CallControlException $exception) {
            return ['status' => 'error', 'reason' => $exception->getMessage()];
        }

        return ['status' => 'voicemail_gather_started'];
    }

    /**
     * @param array<string, mixed> $clientState
     * @return array<string, mixed>
     */
    private function startVoicemailRecording(string $callControlId, array $clientState, string $eventId): array
    {
        try {
            $this->callControlClient->startRecording(
                $callControlId,
                new CallControlRecordingOptions(
                    format: 'mp3',
                    channels: 'single',
                    clientState: $this->encodeClientState($this->withStage($clientState, self::VOICEMAIL_RECORDING_STAGE)),
                    commandId: $eventId . '-record-start',
                    playBeep: true,
                    maxLength: self::VOICEMAIL_MAX_LENGTH_SECONDS,
                    timeoutSeconds: 5
                )
            );
        } catch (CallControlException $exception) {
            return ['status' => 'error', 'reason' => $exception->getMessage()];
        }

        return ['status' => 'voicemail_recording_started'];
    }

    /**
     * @param array<string, mixed> $clientState
     * @return array<string, mixed>
     */
    private function speakText(string $callControlId, string $message, array $clientState, string $commandId): array
    {
        try {
            $this->callControlClient->speakText(
                $callControlId,
                $message,
                $this->sayVoice,
                $this->sayVoice === 'alice' ? $this->sayLanguage : null,
                $this->encodeClientState($clientState),
                $commandId
            );
        } catch (CallControlException $exception) {
            return ['status' => 'error', 'reason' => $exception->getMessage()];
        }

        return [
            'status' => 'voicemail_prompt_started',
            'stage' => $clientState['stage'] ?? '',
        ];
    }

    private function hangupCall(string $callControlId, string $commandId): array
    {
        try {
            $this->callControlClient->hangup($callControlId, $commandId);
        } catch (CallControlException $exception) {
            return ['status' => 'error', 'reason' => $exception->getMessage()];
        }

        return ['status' => 'call_completed'];
    }

    /**
     * @param array<string, mixed> $clientState
     * @return array<string, mixed>
     */
    private function withStage(array $clientState, string $stage): array
    {
        $clientState['flow'] = 'voicemail';
        $clientState['stage'] = $stage;

        return $clientState;
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
}
