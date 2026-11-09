<?php

declare(strict_types=1);

namespace App\Services;

final class LegacyScrypt
{
    public static function verify(string $password, string $stored): bool
    {
        if (!preg_match('/^([a-f0-9]{32}):([a-f0-9]{128})$/D', $stored, $match)) {
            return false;
        }
        $block = hash_pbkdf2('sha256', $password, $match[1], 1, 1024, true);
        $memory = [];
        for ($index = 0; $index < 16384; $index++) {
            $memory[] = $block;
            $block = self::mix($block);
        }
        for ($index = 0; $index < 16384; $index++) {
            $position = unpack('V', substr($block, 960, 4))[1] & 16383;
            $block = self::mix($block ^ $memory[$position]);
        }
        $derived = hash_pbkdf2('sha256', $password, $block, 1, 64, true);
        return hash_equals($match[2], bin2hex($derived));
    }

    private static function mix(string $block): string
    {
        $last = substr($block, 960, 64);
        $even = '';
        $odd = '';
        for ($index = 0; $index < 16; $index++) {
            $last = self::salsa($last ^ substr($block, $index * 64, 64));
            if ($index % 2 === 0) {
                $even .= $last;
            } else {
                $odd .= $last;
            }
        }
        return $even . $odd;
    }

    private static function salsa(string $block): string
    {
        $words = array_values(unpack('V16', $block));
        $x0 = $words[0];
        $x1 = $words[1];
        $x2 = $words[2];
        $x3 = $words[3];
        $x4 = $words[4];
        $x5 = $words[5];
        $x6 = $words[6];
        $x7 = $words[7];
        $x8 = $words[8];
        $x9 = $words[9];
        $x10 = $words[10];
        $x11 = $words[11];
        $x12 = $words[12];
        $x13 = $words[13];
        $x14 = $words[14];
        $x15 = $words[15];
        for ($round = 0; $round < 4; $round++) {
            $sum = ($x0 + $x12) & 0xffffffff;
            $x4 ^= (($sum << 7) | ($sum >> 25)) & 0xffffffff;
            $sum = ($x4 + $x0) & 0xffffffff;
            $x8 ^= (($sum << 9) | ($sum >> 23)) & 0xffffffff;
            $sum = ($x8 + $x4) & 0xffffffff;
            $x12 ^= (($sum << 13) | ($sum >> 19)) & 0xffffffff;
            $sum = ($x12 + $x8) & 0xffffffff;
            $x0 ^= (($sum << 18) | ($sum >> 14)) & 0xffffffff;
            $sum = ($x5 + $x1) & 0xffffffff;
            $x9 ^= (($sum << 7) | ($sum >> 25)) & 0xffffffff;
            $sum = ($x9 + $x5) & 0xffffffff;
            $x13 ^= (($sum << 9) | ($sum >> 23)) & 0xffffffff;
            $sum = ($x13 + $x9) & 0xffffffff;
            $x1 ^= (($sum << 13) | ($sum >> 19)) & 0xffffffff;
            $sum = ($x1 + $x13) & 0xffffffff;
            $x5 ^= (($sum << 18) | ($sum >> 14)) & 0xffffffff;
            $sum = ($x10 + $x6) & 0xffffffff;
            $x14 ^= (($sum << 7) | ($sum >> 25)) & 0xffffffff;
            $sum = ($x14 + $x10) & 0xffffffff;
            $x2 ^= (($sum << 9) | ($sum >> 23)) & 0xffffffff;
            $sum = ($x2 + $x14) & 0xffffffff;
            $x6 ^= (($sum << 13) | ($sum >> 19)) & 0xffffffff;
            $sum = ($x6 + $x2) & 0xffffffff;
            $x10 ^= (($sum << 18) | ($sum >> 14)) & 0xffffffff;
            $sum = ($x15 + $x11) & 0xffffffff;
            $x3 ^= (($sum << 7) | ($sum >> 25)) & 0xffffffff;
            $sum = ($x3 + $x15) & 0xffffffff;
            $x7 ^= (($sum << 9) | ($sum >> 23)) & 0xffffffff;
            $sum = ($x7 + $x3) & 0xffffffff;
            $x11 ^= (($sum << 13) | ($sum >> 19)) & 0xffffffff;
            $sum = ($x11 + $x7) & 0xffffffff;
            $x15 ^= (($sum << 18) | ($sum >> 14)) & 0xffffffff;
            $sum = ($x0 + $x3) & 0xffffffff;
            $x1 ^= (($sum << 7) | ($sum >> 25)) & 0xffffffff;
            $sum = ($x1 + $x0) & 0xffffffff;
            $x2 ^= (($sum << 9) | ($sum >> 23)) & 0xffffffff;
            $sum = ($x2 + $x1) & 0xffffffff;
            $x3 ^= (($sum << 13) | ($sum >> 19)) & 0xffffffff;
            $sum = ($x3 + $x2) & 0xffffffff;
            $x0 ^= (($sum << 18) | ($sum >> 14)) & 0xffffffff;
            $sum = ($x5 + $x4) & 0xffffffff;
            $x6 ^= (($sum << 7) | ($sum >> 25)) & 0xffffffff;
            $sum = ($x6 + $x5) & 0xffffffff;
            $x7 ^= (($sum << 9) | ($sum >> 23)) & 0xffffffff;
            $sum = ($x7 + $x6) & 0xffffffff;
            $x4 ^= (($sum << 13) | ($sum >> 19)) & 0xffffffff;
            $sum = ($x4 + $x7) & 0xffffffff;
            $x5 ^= (($sum << 18) | ($sum >> 14)) & 0xffffffff;
            $sum = ($x10 + $x9) & 0xffffffff;
            $x11 ^= (($sum << 7) | ($sum >> 25)) & 0xffffffff;
            $sum = ($x11 + $x10) & 0xffffffff;
            $x8 ^= (($sum << 9) | ($sum >> 23)) & 0xffffffff;
            $sum = ($x8 + $x11) & 0xffffffff;
            $x9 ^= (($sum << 13) | ($sum >> 19)) & 0xffffffff;
            $sum = ($x9 + $x8) & 0xffffffff;
            $x10 ^= (($sum << 18) | ($sum >> 14)) & 0xffffffff;
            $sum = ($x15 + $x14) & 0xffffffff;
            $x12 ^= (($sum << 7) | ($sum >> 25)) & 0xffffffff;
            $sum = ($x12 + $x15) & 0xffffffff;
            $x13 ^= (($sum << 9) | ($sum >> 23)) & 0xffffffff;
            $sum = ($x13 + $x12) & 0xffffffff;
            $x14 ^= (($sum << 13) | ($sum >> 19)) & 0xffffffff;
            $sum = ($x14 + $x13) & 0xffffffff;
            $x15 ^= (($sum << 18) | ($sum >> 14)) & 0xffffffff;
        }
        return pack(
            'V16',
            ($x0 + $words[0]) & 0xffffffff,
            ($x1 + $words[1]) & 0xffffffff,
            ($x2 + $words[2]) & 0xffffffff,
            ($x3 + $words[3]) & 0xffffffff,
            ($x4 + $words[4]) & 0xffffffff,
            ($x5 + $words[5]) & 0xffffffff,
            ($x6 + $words[6]) & 0xffffffff,
            ($x7 + $words[7]) & 0xffffffff,
            ($x8 + $words[8]) & 0xffffffff,
            ($x9 + $words[9]) & 0xffffffff,
            ($x10 + $words[10]) & 0xffffffff,
            ($x11 + $words[11]) & 0xffffffff,
            ($x12 + $words[12]) & 0xffffffff,
            ($x13 + $words[13]) & 0xffffffff,
            ($x14 + $words[14]) & 0xffffffff,
            ($x15 + $words[15]) & 0xffffffff,
        );
    }
}
