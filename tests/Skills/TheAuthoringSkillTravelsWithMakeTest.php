<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Skills;

use Milpa\DevTools\Operations\MakeHandler;
use Milpa\DevTools\Support\RootResolver;
use PHPUnit\Framework\TestCase;

/**
 * THE SKILL ABOUT `make` TRAVELS WITH `make` (greenhouse decisions/0592).
 *
 * `governed-authoring` lived in another repository and was copied into an image by a Dockerfile, so a house born
 * from `composer create-project` never had it — and nothing held its text to the tools it describes. Measured with
 * a real resident (greenhouse evidence/1127): it named `controller` and `crud` in six lines and «operation» in one,
 * and said nothing of promoting a trial. Three runs of three stopped exactly there.
 *
 * It ships here now, beside the tools it is about, and these tests hold it to them: a skill that names a kind
 * `make` does not have, or forgets one it has, is red.
 */
final class TheAuthoringSkillTravelsWithMakeTest extends TestCase
{
    private const SKILL = __DIR__ . '/../../resources/skills/governed-authoring/SKILL.md';

    public function testItIsASkillAHouseCanRead(): void
    {
        [$front, $body] = $this->parts();

        self::assertSame('governed-authoring', $front['name']);
        self::assertNotSame('', $front['description'], 'a skill with no description is never reached for');
        self::assertNotSame('', trim($body));
    }

    public function testItSaysWhichToolsItNeedsSoAHouseWithoutThemIsNotToldOfIt(): void
    {
        [$front] = $this->parts();

        self::assertSame(['make', 'implement'], array_map('trim', explode(',', $front['requires'] ?? '')));
    }

    public function testItNamesEveryKindMakeHasAndNoneItDoesNot(): void
    {
        [, $body] = $this->parts();
        self::assertSame(1, preg_match_all('/`make what=([a-z]+(?:\|[a-z]+)+)`/', $body, $lines), 'one line lists the kinds, joined by |');
        $line = [1 => $lines[1][0]];
        $named = explode('|', $line[1]);
        $real = (new MakeHandler(new RootResolver(sys_get_temp_dir())))->kinds();
        sort($named);
        sort($real);

        self::assertSame($real, $named);
    }

    public function testEveryScaffoldItPointsAtIsAKindMakeHas(): void
    {
        [, $body] = $this->parts();
        $real = (new MakeHandler(new RootResolver(sys_get_temp_dir())))->kinds();
        preg_match_all('/`make what=([a-z]+)`/', $body, $pointed);

        self::assertNotSame([], $pointed[1], 'the control: the skill points at scaffolds by name');
        foreach (array_unique($pointed[1]) as $kind) {
            self::assertContains($kind, $real, "the skill sends a resident to `make what={$kind}`, which make does not have");
        }
    }

    public function testItSaysThatWhatAnAgentWorksWithIsAnOperation(): void
    {
        [, $body] = $this->parts();

        self::assertStringContainsString('`make what=operation`', $body);
        self::assertStringContainsString('entity=<Entity>', $body, 'and how an operation reaches the rows it works over');
        self::assertStringContainsString('operation=domain:verb', $body);
    }

    /**
     * AN EXAMPLE IS NOT THE EXAM (greenhouse decisions/0594 §5). This skill said which verbs were operations by
     * listing the verbs of the very request a resident was being measured with, and showed an entity by naming
     * that request's own. What a resident reads here names the FORM of an argument — a placeholder — never a domain.
     */
    public function testEveryArgumentItShowsIsAFormNotADomain(): void
    {
        [, $body] = $this->parts();
        preg_match_all('/\b(entity|operation|plugin|name|fields|needs)=([^\s`,)]+)/', $body, $shown, PREG_SET_ORDER);

        self::assertNotSame([], $shown, 'the control: the skill shows arguments');
        foreach ($shown as [$whole, , $value]) {
            self::assertMatchesRegularExpression('/^(<[A-Za-z]+>|domain:verb)$/', $value, "«{$whole}» names a domain: show the form");
        }
    }

    public function testItSaysThatATrialIsPromotedBeforeWhatItScaffoldedIsFilled(): void
    {
        [, $body] = $this->parts();

        self::assertStringContainsString('ran_in_trial', $body);
        self::assertStringContainsString('`make` → `sandbox:promote` → `implement` → `sandbox:promote`', $body);
        self::assertStringContainsString('Promote one `make` before the next', $body);
    }

    public function testItDoesNotSendAResidentToAFlagNoToolTakes(): void
    {
        [, $body] = $this->parts();

        self::assertStringNotContainsString('--what', $body, 'a tool call names its argument: what=…');
        self::assertStringNotContainsString('make:crud', $body);
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function parts(): array
    {
        self::assertFileExists(self::SKILL);
        self::assertSame(1, preg_match('/^---\R(.*?)\R---\R(.*)$/s', trim((string) file_get_contents(self::SKILL)), $m), 'frontmatter between --- fences, then the body');
        $front = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^\s*([a-z0-9_-]+)\s*:\s*(.*)$/i', $line, $pair) === 1) {
                $front[strtolower($pair[1])] = trim($pair[2]);
            }
        }

        return [$front, $m[2]];
    }
}
