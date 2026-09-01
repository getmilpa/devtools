<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Test;

/**
 * Turns a PHPUnit JUnit XML report into a flat, comparable map of test identity to outcome.
 *
 * A baseline/delta needs to know WHICH tests failed, not merely how many — counts cannot tell a newly
 * broken test from one that was already red. PHPUnit's `--log-junit` is the machine-readable form that
 * carries that identity, one `<testcase>` per test with its failure/error/skipped children. This parser
 * is deliberately pure (a string in, an array out) so the diff logic and the handler that runs the
 * subprocess can each be tested without the other.
 */
final class JUnitParser
{
    /**
     * Parses a JUnit XML document into `"Class::method" => status`, where status is one of
     * `passed`, `failed`, `errored`, or `skipped`.
     *
     * A testcase with an `<error>` child is `errored`, with a `<failure>` child is `failed`, with a
     * `<skipped>` child is `skipped`, and otherwise `passed`. Error outranks failure because a testcase
     * can carry both and an errored test is the stronger signal about what broke.
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException when the document is not well-formed XML
     */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new \InvalidArgumentException('empty JUnit report');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || $errors !== []) {
            throw new \InvalidArgumentException('malformed JUnit report');
        }

        $results = [];
        foreach ($doc->getElementsByTagName('testcase') as $case) {
            $name = trim($case->getAttribute('name'));
            $class = trim($case->getAttribute('class'));
            if ($class === '') {
                $class = trim($case->getAttribute('classname'));
            }
            if ($name === '') {
                continue;
            }

            $id = $class !== '' ? $class . '::' . $name : $name;
            $results[$id] = $this->status($case);
        }

        return $results;
    }

    /**
     * The outcome of a single `<testcase>`, read from its children.
     */
    private function status(\DOMElement $case): string
    {
        if ($case->getElementsByTagName('error')->length > 0) {
            return 'errored';
        }
        if ($case->getElementsByTagName('failure')->length > 0) {
            return 'failed';
        }
        if ($case->getElementsByTagName('skipped')->length > 0) {
            return 'skipped';
        }

        return 'passed';
    }
}
