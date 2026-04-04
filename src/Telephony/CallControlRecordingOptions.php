<?php

namespace App\Telephony;

final class CallControlRecordingOptions
{
    public function __construct(
        public readonly string $format = 'mp3',
        public readonly string $channels = 'single',
        public readonly ?string $clientState = null,
        public readonly ?string $commandId = null,
        public readonly bool $playBeep = true,
        public readonly int $maxLength = 120,
        public readonly int $timeoutSeconds = 5
    ) {
    }
}
