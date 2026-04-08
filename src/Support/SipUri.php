<?php

namespace App\Support;

final class SipUri
{
    public static function isValid(string $sipUri): bool
    {
        $uriWithoutScheme = self::stripScheme($sipUri);
        $host = false;

        if (is_string($uriWithoutScheme) && $uriWithoutScheme !== '') {
            $atPosition = strrpos($uriWithoutScheme, '@');
            $host = $atPosition === false ? $uriWithoutScheme : substr($uriWithoutScheme, $atPosition + 1);

            if (is_string($host) && $host !== '') {
                $host = preg_replace('/[:;?].*$/', '', $host);
            }
        }

        return is_string($host) && $host !== '' && preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1;
    }

    private static function stripScheme(string $sipUri): string|false
    {
        if (str_starts_with($sipUri, 'sip:')) {
            return substr($sipUri, 4);
        }

        if (str_starts_with($sipUri, 'sips:')) {
            return substr($sipUri, 5);
        }

        return false;
    }
}
