<?php

namespace App\Telephony;

use App\Exceptions\CallControlException;

final class TelnyxCallControlClient implements CallControlClientInterface
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.telnyx.com/v2')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function answer(string $callControlId, ?string $commandId = null): void
    {
        $payload = [];

        if ($commandId !== null && $commandId !== '') {
            $payload['command_id'] = $commandId;
        }

        $this->sendCommand($callControlId, 'answer', $payload);
    }

    public function bridge(
        string $callControlId,
        string $callControlIdToBridgeWith,
        ?string $clientState = null,
        ?string $commandId = null
    ): void {
        $payload = [
            'call_control_id' => $callControlIdToBridgeWith,
        ];

        if ($clientState !== null && $clientState !== '') {
            $payload['client_state'] = $clientState;
        }

        if ($commandId !== null && $commandId !== '') {
            $payload['command_id'] = $commandId;
        }

        $this->sendCommand($callControlId, 'bridge', $payload);
    }

    public function speakText(
        string $callControlId,
        string $payload,
        string $voice,
        ?string $language = null,
        ?string $clientState = null,
        ?string $commandId = null
    ): void {
        $requestPayload = [
            'payload' => $payload,
            'voice' => $voice,
        ];

        if ($language !== null && $language !== '' && $voice === 'alice') {
            $requestPayload['language'] = $language;
        }

        if ($clientState !== null && $clientState !== '') {
            $requestPayload['client_state'] = $clientState;
        }

        if ($commandId !== null && $commandId !== '') {
            $requestPayload['command_id'] = $commandId;
        }

        $this->sendCommand($callControlId, 'speak', $requestPayload);
    }

    public function gather(
        string $callControlId,
        string $validDigits = '123',
        ?string $clientState = null,
        ?string $commandId = null,
        int $maximumDigits = 1,
        int $initialTimeoutMillis = 5000
    ): void {
        $payload = [
            'minimum_digits' => 1,
            'maximum_digits' => $maximumDigits,
            'initial_timeout_millis' => $initialTimeoutMillis,
            'valid_digits' => $validDigits,
            'terminating_digit' => '#',
        ];

        if ($clientState !== null && $clientState !== '') {
            $payload['client_state'] = $clientState;
        }

        if ($commandId !== null && $commandId !== '') {
            $payload['command_id'] = $commandId;
        }

        $this->sendCommand($callControlId, 'gather', $payload);
    }

    public function startRecording(string $callControlId, ?CallControlRecordingOptions $options = null): void
    {
        $options ??= new CallControlRecordingOptions();

        $payload = [
            'format' => $options->format,
            'channels' => $options->channels,
            'play_beep' => $options->playBeep,
            'max_length' => $options->maxLength,
            'timeout_secs' => $options->timeoutSeconds,
        ];

        if ($options->clientState !== null && $options->clientState !== '') {
            $payload['client_state'] = $options->clientState;
        }

        if ($options->commandId !== null && $options->commandId !== '') {
            $payload['command_id'] = $options->commandId;
        }

        $this->sendCommand($callControlId, 'record_start', $payload);
    }

    public function hangup(string $callControlId, ?string $commandId = null): void
    {
        $payload = [];

        if ($commandId !== null && $commandId !== '') {
            $payload['command_id'] = $commandId;
        }

        $this->sendCommand($callControlId, 'hangup', $payload);
    }

    public function dial(
        string $callControlId,
        string $destination,
        string $from,
        ?CallControlDialOptions $options = null
    ): void {
        $options ??= new CallControlDialOptions();

        $this->createCall($this->buildDialPayload($callControlId, $destination, $from, $options));
    }

    private function buildDialPayload(
        string $callControlId,
        string $destination,
        string $from,
        CallControlDialOptions $options
    ): array {
        $payload = [
            'to' => $destination,
            'from' => $from,
            'bridge_intent' => $options->bridgeIntent,
        ];

        $this->appendStringValue($payload, 'connection_id', $options->connectionId);
        $this->appendStringValue($payload, 'link_to', $this->resolveLinkTo($callControlId, $options));

        if ($options->bridgeOnAnswer) {
            $payload['bridge_on_answer'] = true;
        }

        $this->appendStringValue($payload, 'answering_machine_detection', $options->answeringMachineDetection);

        if ($options->answeringMachineDetectionConfig !== null) {
            $amdConfig = $options->answeringMachineDetectionConfig->toArray();

            if ($amdConfig !== []) {
                $payload['answering_machine_detection_config'] = $amdConfig;
            }
        }

        $this->appendStringValue($payload, 'client_state', $options->clientState);

        if ($options->timeoutSeconds !== null) {
            $payload['timeout_secs'] = $options->timeoutSeconds;
        }

        $this->appendStringValue($payload, 'command_id', $options->commandId);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendCommand(string $callControlId, string $action, array $payload): void
    {
        $url = $this->baseUrl . '/calls/' . rawurlencode($callControlId) . '/actions/' . rawurlencode($action);
        $jsonPayload = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        if (function_exists('curl_init')) {
            $this->sendWithCurl($url, $jsonPayload);

            return;
        }

        $this->sendWithStreams($url, $jsonPayload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createCall(array $payload): void
    {
        $url = $this->baseUrl . '/calls';
        $jsonPayload = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        if (function_exists('curl_init')) {
            $this->sendWithCurl($url, $jsonPayload);

            return;
        }

        $this->sendWithStreams($url, $jsonPayload);
    }

    private function resolveLinkTo(string $callControlId, CallControlDialOptions $options): ?string
    {
        if ($options->linkTo !== null && $options->linkTo !== '') {
            return $options->linkTo;
        }

        return $callControlId !== '' ? $callControlId : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendStringValue(array &$payload, string $key, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $payload[$key] = $value;
        }
    }

    private function sendWithCurl(string $url, string $jsonPayload): void
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new CallControlException('Failed to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($handle);
        $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            $responseBody = is_string($response) ? $response : '';
            $errorMessage = $response === false
                ? curl_error($handle)
                : $this->formatHttpError($url, $statusCode, $responseBody);
            curl_close($handle);

            throw new CallControlException('Call Control command failed: ' . $errorMessage);
        }

        curl_close($handle);
    }

    private function sendWithStreams(string $url, string $jsonPayload): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ]),
                'content' => $jsonPayload,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusLine = is_array($responseHeaders) && isset($responseHeaders[0]) ? $responseHeaders[0] : '';
        $statusCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1 ? (int) $matches[1] : 0;

        if ($result === false || $statusCode < 200 || $statusCode >= 300) {
            throw new CallControlException(
                'Call Control command failed: ' . $this->formatHttpError($url, $statusCode, is_string($result) ? $result : '')
            );
        }
    }

    private function formatHttpError(string $url, int $statusCode, string $responseBody): string
    {
        $message = 'Unexpected Telnyx API status ' . $statusCode . ' for ' . $url;
        $responseBody = trim($responseBody);

        if ($responseBody === '') {
            return $message;
        }

        if (strlen($responseBody) > 1000) {
            $responseBody = substr($responseBody, 0, 1000) . '...';
        }

        return $message . ' with response: ' . $responseBody;
    }
}
