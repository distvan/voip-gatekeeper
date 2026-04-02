<?php

namespace App\Support;

final class E164PhoneNumber
{
    private const PATTERN = '/^\+[1-9]\d{6,14}$/';

    public static function isValid(string $phoneNumber): bool
    {
        return preg_match(self::PATTERN, $phoneNumber) === 1;
    }

    /**
     * @return array<string, bool>
     */
    public static function parseCommaSeparatedList(string $phoneNumbers): array
    {
        $parsedPhoneNumbers = [];

        foreach (explode(',', $phoneNumbers) as $phoneNumber) {
            $normalizedPhoneNumber = trim($phoneNumber);

            if (!self::isValid($normalizedPhoneNumber)) {
                throw new \InvalidArgumentException('Invalid E.164 phone number.');
            }

            $parsedPhoneNumbers[$normalizedPhoneNumber] = true;
        }

        return $parsedPhoneNumbers;
    }
}
