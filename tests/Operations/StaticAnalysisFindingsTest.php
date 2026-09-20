<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\DevTools\Operations\StaticAnalysisFindings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Incomplete or foreign reports cannot manufacture an attributed rule finding. */
final class StaticAnalysisFindingsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function report(): array
    {
        return ['totals' => ['errors' => 0, 'file_errors' => 1], 'files' => ['/app/Subject.php' => [
            'errors' => 1, 'messages' => [['message' => 'Unknown contract in /app/Subject.php.',
                'identifier' => 'class.notFound', 'line' => 7, 'ignorable' => true]],
        ]], 'errors' => []];
    }

    public function testCanonicalInformationIgnoresLinesOrderDuplicatesAndWorkspacePrefix(): void
    {
        $findings = StaticAnalysisFindings::fromJson(json_encode(self::report(), JSON_THROW_ON_ERROR), '/app/Subject.php', '/app');
        self::assertSame([['message' => 'Unknown contract in Subject.php.', 'identifier' => 'class.notFound', 'line' => 7]], $findings);
        $other = str_replace('/app/', '/other/', json_encode(self::report(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        self::assertSame($findings, StaticAnalysisFindings::fromJson($other, '/other/Subject.php', '/other'));
        $first = $findings[0];
        $second = [...$first, 'message' => 'A different contract.'];
        $fingerprint = StaticAnalysisFindings::fingerprint([$first, $second]);
        self::assertNotNull($fingerprint);
        self::assertSame($fingerprint, StaticAnalysisFindings::fingerprint([$second, [...$first, 'line' => 900], $first]));
        self::assertSame(StaticAnalysisFindings::fingerprint([$first]), StaticAnalysisFindings::fingerprint([[...$first, 'line' => null]]));
        self::assertNotSame($fingerprint, StaticAnalysisFindings::fingerprint([$first]));
        self::assertNotSame(StaticAnalysisFindings::fingerprint([$first]), StaticAnalysisFindings::fingerprint([[...$first, 'identifier' => 'method.notFound']]));
    }

    /** @return iterable<string, array{string}> */
    public static function badReports(): iterable
    {
        foreach (['', '{', 'null', '[]', '{"totals":'] as $index => $json) {
            yield 'invalid-json-' . $index => [$json];
        }
        $base = self::report();
        foreach (['errors' => ['Internal error'], 'files' => [], 'totals' => ['errors' => 1, 'file_errors' => 1]] as $field => $value) {
            yield $field => [json_encode([...$base, $field => $value], JSON_THROW_ON_ERROR)];
        }
        $bad = $base;
        $bad['files']['/other.php'] = $bad['files']['/app/Subject.php'];
        yield 'foreign-file' => [json_encode($bad, JSON_THROW_ON_ERROR)];
        foreach ([0, '1', 2, null] as $index => $count) {
            $bad = $base;
            $bad['totals']['file_errors'] = $count;
            yield 'total-' . $index => [json_encode($bad, JSON_THROW_ON_ERROR)];
        }
        foreach ([null, ['errors' => 0, 'messages' => []], ['errors' => 1, 'messages' => []], ['errors' => 1, 'messages' => ['named' => []]]] as $index => $file) {
            $bad = $base;
            $bad['files']['/app/Subject.php'] = $file;
            yield 'file-' . $index => [json_encode($bad, JSON_THROW_ON_ERROR)];
        }
        foreach (['ignorable' => false, 'identifier' => null, 'message' => 9, 'line' => -1] as $field => $value) {
            $bad = $base;
            $bad['files']['/app/Subject.php']['messages'][0][$field] = $value;
            yield 'message-' . $field => [json_encode($bad, JSON_THROW_ON_ERROR)];
        }
        $bad = $base;
        unset($bad['files']['/app/Subject.php']['messages'][0]['line']);
        yield 'missing-line' => [json_encode($bad, JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('badReports')]
    public function testIncompleteForeignAndInfrastructureReportsAreNotRuleFindings(string $json): void
    {
        self::assertNull(StaticAnalysisFindings::fromJson($json, '/app/Subject.php', '/app'));
    }

    public function testMalformedReceiptFindingsCannotBeFingerprinted(): void
    {
        $valid = ['message' => 'Unknown class.', 'identifier' => 'class.notFound', 'line' => 1];
        foreach ([null, [], 'prose', ['named' => $valid], [null], [[...$valid, 'extra' => true]],
            [[...$valid, 'message' => '']], [[...$valid, 'identifier' => ' ']], [[...$valid, 'line' => '1']],
            [[...$valid, 'message' => "\xff"]]] as $bad) {
            self::assertNull(StaticAnalysisFindings::fingerprint($bad));
        }
    }
}
