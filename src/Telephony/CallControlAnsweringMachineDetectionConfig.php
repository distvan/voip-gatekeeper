<?php

namespace App\Telephony;

final class CallControlAnsweringMachineDetectionConfig
{
    public function __construct(
        public readonly ?int $afterGreetingSilenceMillis = null,
        public readonly ?int $betweenWordsSilenceMillis = null,
        public readonly ?int $greetingDurationMillis = null,
        public readonly ?int $greetingSilenceDurationMillis = null,
        public readonly ?int $greetingTotalAnalysisTimeMillis = null,
        public readonly ?int $initialSilenceMillis = null,
        public readonly ?int $maximumNumberOfWords = null,
        public readonly ?int $maximumWordLengthMillis = null,
        public readonly ?int $silenceThreshold = null,
        public readonly ?int $totalAnalysisTimeMillis = null
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return array_filter([
            'after_greeting_silence_millis' => $this->afterGreetingSilenceMillis,
            'between_words_silence_millis' => $this->betweenWordsSilenceMillis,
            'greeting_duration_millis' => $this->greetingDurationMillis,
            'greeting_silence_duration_millis' => $this->greetingSilenceDurationMillis,
            'greeting_total_analysis_time_millis' => $this->greetingTotalAnalysisTimeMillis,
            'initial_silence_millis' => $this->initialSilenceMillis,
            'maximum_number_of_words' => $this->maximumNumberOfWords,
            'maximum_word_length_millis' => $this->maximumWordLengthMillis,
            'silence_threshold' => $this->silenceThreshold,
            'total_analysis_time_millis' => $this->totalAnalysisTimeMillis,
        ], static fn (?int $value): bool => $value !== null);
    }
}
