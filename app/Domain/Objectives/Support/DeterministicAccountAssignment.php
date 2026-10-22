<?php

namespace App\Domain\Objectives\Support;

use App\Models\GtmExperimentArm;
use InvalidArgumentException;

final class DeterministicAccountAssignment
{
    public const ALGORITHM_VERSION = 'hmac_sha256_basis_points_v1';

    public static function bucket(string $seed, int $accountId): int
    {
        if (strlen($seed) !== 32) {
            throw new InvalidArgumentException('The assignment seed must contain exactly 256 bits.');
        }
        $bytes = substr(hash_hmac('sha256', 'account:'.$accountId, $seed, true), 0, 8);
        $parts = unpack('Nhigh/Nlow', $bytes);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('The assignment digest could not be decoded.');
        }

        // Compute the unsigned 64-bit value modulo 10,000 without relying on
        // platform integer overflow. 2^32 mod 10,000 is 7,296.
        return ((((int) $parts['high'] % 10000) * 7296) + ((int) $parts['low'] % 10000)) % 10000;
    }

    /** @param iterable<int, GtmExperimentArm> $arms */
    public static function armForBucket(iterable $arms, int $bucket): GtmExperimentArm
    {
        $ceiling = 0;
        foreach ($arms as $arm) {
            $ceiling += (int) $arm->allocation_basis_points;
            if ($bucket < $ceiling) {
                return $arm;
            }
        }

        throw new InvalidArgumentException('Experiment allocations do not cover every basis-point bucket.');
    }
}
