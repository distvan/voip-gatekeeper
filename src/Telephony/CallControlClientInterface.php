<?php

namespace App\Telephony;

interface CallControlClientInterface
{
    public function answer(string $callControlId, ?string $commandId = null): void;

    public function bridge(
        string $callControlId,
        string $callControlIdToBridgeWith,
        ?string $clientState = null,
        ?string $commandId = null
    ): void;

    public function speakText(
        string $callControlId,
        string $payload,
        string $voice,
        ?string $language = null,
        ?string $clientState = null,
        ?string $commandId = null
    ): void;

    public function gather(
        string $callControlId,
        string $validDigits = '123',
        ?string $clientState = null,
        ?string $commandId = null,
        int $maximumDigits = 1,
        int $initialTimeoutMillis = 5000
    ): void;

    public function startRecording(string $callControlId, ?CallControlRecordingOptions $options = null): void;

    public function hangup(string $callControlId, ?string $commandId = null): void;

    public function dial(
        string $callControlId,
        string $destination,
        string $from,
        ?CallControlDialOptions $options = null
    ): void;
}
