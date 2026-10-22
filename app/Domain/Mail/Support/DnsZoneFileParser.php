<?php

namespace App\Domain\Mail\Support;

/**
 * Parse the public records returned by managed mail providers.
 *
 * Stalwart may flatten a complete zone onto one line, so line-oriented DNS
 * parsing is not sufficient. Record boundaries are identified from each
 * owner/TTL/IN/type preamble while quoted TXT chunks remain part of the
 * preceding record value.
 */
final class DnsZoneFileParser
{
    /**
     * @return list<array{host: string, type: string, value: string}>
     */
    public function records(string $zoneFile): array
    {
        $zoneFile = trim($zoneFile);
        if ($zoneFile === '') {
            return [];
        }

        $matched = preg_match_all(
            '/(?:\A|(?<=\s))(?<host>[a-z0-9_@*.-]+)\s+(?:(?:\d+)\s+)?IN\s+(?<type>[a-z][a-z0-9]*)\s+/i',
            $zoneFile,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $records = [];
        for ($index = 0; $index < $matched; $index++) {
            $fullMatch = $matches[0][$index][0];
            $offset = $matches[0][$index][1];
            $valueStart = $offset + strlen($fullMatch);
            $valueEnd = $index + 1 < $matched
                ? $matches[0][$index + 1][1]
                : strlen($zoneFile);
            $value = trim(substr($zoneFile, $valueStart, $valueEnd - $valueStart));

            if ($value === '') {
                continue;
            }

            $records[] = [
                'host' => $matches['host'][$index][0],
                'type' => strtoupper($matches['type'][$index][0]),
                'value' => $value,
            ];
        }

        return $records;
    }

    public function txtValue(string $value): ?string
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $value, $matches);
        if ($matches[1] !== []) {
            return implode('', array_map(
                static fn (string $part): string => stripcslashes($part),
                $matches[1],
            ));
        }

        $value = trim($value, "() \t\r\n");

        return $value === '' ? null : trim($value, '"');
    }
}
