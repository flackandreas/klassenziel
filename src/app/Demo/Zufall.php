<?php

declare(strict_types=1);

namespace Klassenziel\Demo;

/**
 * Fest verdrahteter Zufall fuer die Demo: derselbe Startwert ergibt immer
 * dieselbe Folge, auf jedem Rechner und mit jeder PHP-Version. mt_rand
 * waere dafuer zu unsicher - es haengt am globalen Zustand.
 */
final class Zufall
{
    private int $zustand;

    public function __construct(string $startwert)
    {
        $this->zustand = (crc32($startwert) ?: 0x9E3779B9) & 0xFFFFFFFF;
    }

    /** Eine Zahl in [0, 1). */
    public function zahl(): float
    {
        // xorshift32
        $x = $this->zustand;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->zustand = $x & 0xFFFFFFFF;

        return $this->zustand / 4294967296;
    }

    /** Ein Index in [0, $anzahl). */
    public function index(int $anzahl): int
    {
        return min($anzahl - 1, (int) floor($this->zahl() * $anzahl));
    }
}
