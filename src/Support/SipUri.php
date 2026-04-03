<?php

namespace App\Support;

final class SipUri
{
    public static function isValid(string $sipUri): bool
    {
        $uriWithoutScheme = str_starts_with($sipUri, 'sip:') ? substr($sipUri, 4) : false;
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
}
