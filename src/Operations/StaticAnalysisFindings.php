<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Operations;

/** Attribute a complete PHPStan report; identify findings separately from proposed bytes. */
final class StaticAnalysisFindings
{
    /** Read only rule findings belonging to the one analyzed subject, without global errors.
     * @return list<array{message: string, identifier: string, line: int|null}>|null
     */
    public static function fromJson(string $json, string $subject, string $root): ?array
    {
        $report = json_decode($json, true);
        if (!is_array($report) || ($report['errors'] ?? null) !== []
            || !is_array($report['totals'] ?? null) || ($report['totals']['errors'] ?? null) !== 0
            || !is_int($report['totals']['file_errors'] ?? null) || $report['totals']['file_errors'] < 1
            || !is_array($report['files'] ?? null) || array_keys($report['files']) !== [$subject]) {
            return null;
        }
        $file = $report['files'][$subject];
        if (!is_array($file) || ($file['errors'] ?? null) !== $report['totals']['file_errors']
            || !is_array($file['messages'] ?? null) || !array_is_list($file['messages'])
            || count($file['messages']) !== $file['errors']) {
            return null;
        }
        $findings = [];
        foreach ($file['messages'] as $message) {
            if (!is_array($message) || ($message['ignorable'] ?? null) !== true
                || !array_key_exists('line', $message)) {
                return null;
            }
            $findings[] = [
                'message' => is_string($message['message'] ?? null)
                    ? str_replace(rtrim($root, '/') . '/', '', $message['message']) : null,
                'identifier' => $message['identifier'] ?? null,
                'line' => $message['line'],
            ];
        }
        if (self::fingerprint($findings) === null) {
            return null;
        }
        /** @var list<array{message: string, identifier: string, line: int|null}> $findings */
        return $findings;
    }

    /** Identify the set of messages and identifiers, ignoring location, ordering and multiplicity.
     * This is observed information equality, not semantic equivalence or a usefulness judgment.
     */
    public static function fingerprint(mixed $findings): ?string
    {
        if (!is_array($findings) || !array_is_list($findings) || $findings === []) {
            return null;
        }
        $pairs = [];
        foreach ($findings as $finding) {
            if (!is_array($finding) || count($finding) !== 3
                || !is_string($finding['message'] ?? null) || trim($finding['message']) === ''
                || !is_string($finding['identifier'] ?? null) || trim($finding['identifier']) === ''
                || !array_key_exists('line', $finding)
                || ($finding['line'] !== null && (!is_int($finding['line']) || $finding['line'] < 1))) {
                return null;
            }
            $pair = json_encode([$finding['identifier'], $finding['message']]);
            if ($pair === false) {
                return null;
            }
            $pairs[] = $pair;
        }
        $pairs = array_values(array_unique($pairs));
        sort($pairs, SORT_STRING);
        return hash('sha256', json_encode($pairs, JSON_THROW_ON_ERROR));
    }
}
