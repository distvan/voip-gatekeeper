<?php

namespace App\Telephony;

final class CallControlDialOptions
{
    public function __construct(
        public readonly ?int $timeoutSeconds = null,
        public readonly bool $bridgeIntent = true,
        public readonly ?string $linkTo = null,
        public readonly bool $bridgeOnAnswer = false,
        public readonly ?string $clientState = null,
        public readonly ?string $commandId = null
    ) {
    }
}
