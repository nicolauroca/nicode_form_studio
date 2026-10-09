<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Http;

final class PublicAddress
{
    public static function allowed(string $address): bool
    {
        $bytes = @inet_pton($address);
        if ($bytes === false) { return false; }
        if (strlen($bytes) === 4) {
            foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'] as $range) {
                if (self::within($bytes, $range)) { return false; }
            }
            return true;
        }
        // Permit ordinary global unicast only, excluding transition, documentation
        // and special-purpose ranges. IPv4-mapped/compatible addresses fail closed.
        if (!self::within($bytes, '2000::/3')) { return false; }
        foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $range) {
            if (self::within($bytes, $range)) { return false; }
        }
        return true;
    }
    private static function within(string $bytes, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr); $prefix = inet_pton($network); $bits = (int) $bits;
        if (strlen($bytes) !== strlen($prefix)) { return false; }
        $whole = intdiv($bits, 8); $rest = $bits % 8;
        return substr($bytes, 0, $whole) === substr($prefix, 0, $whole)
            && ($rest === 0 || (ord($bytes[$whole]) & (255 << (8 - $rest))) === (ord($prefix[$whole]) & (255 << (8 - $rest))));
    }
}
