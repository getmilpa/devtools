<?php

declare(strict_types=1);

namespace Milpa\DevTools\Tests\Operations;

use Milpa\Command\Operation;
use Milpa\DevTools\Operations\DevToolsOperations;
use PHPUnit\Framework\TestCase;

/**
 * `edit` says it AMENDS the class it names (greenhouse decisions/0596).
 *
 * `implement` declares that it creates its named target, so a request that does not name the class is not asked
 * about it; `edit` selects a class that exists, and is. The record of the lab's houses says what that question fell
 * on: 28 of 34 times, a class the same session had brought into the house a moment before. So `edit` declares what it
 * does — it amends, in place, a class that exists — and the session's floor decides, from that session's own record,
 * whether the class is one the session itself brought. Declaring it changes nothing here: this package runs no gate.
 *
 * The declaration was born in a later milpa/command than the oldest this package runs with. Where the installed
 * contract cannot carry it, `edit` is declared exactly as before and the floor keeps asking — which is the answer an
 * older house wants anyway.
 *
 * @guards `edit` carrying the declaration wherever the installed contract can; nothing else in this package carrying
 *         it; the package still declaring its operations on a contract that cannot
 *
 * @refuses `implement` saying it amends (it creates); `edit` saying it creates; an operation with no named target
 *          saying anything about one
 */
final class AnEditAmendsTheClassItNamesTest extends TestCase
{
    public function testEditSaysItAmendsTheClassItNames(): void
    {
        $this->needsAContractThatCanSayIt();

        $edit = $this->operations()['edit'];

        self::assertTrue($edit->amendsNamedTarget);
        self::assertSame('class', $edit->namedTarget, 'and the target it speaks of is the class');
        self::assertFalse($edit->createsNamedTarget, 'amending is not creating: only `make` creates a class');
    }

    public function testImplementKeepsSayingItCreates(): void
    {
        $this->needsAContractThatCanSayIt();

        $implement = $this->operations()['implement'];

        self::assertTrue($implement->createsNamedTarget);
        self::assertFalse($implement->amendsNamedTarget, 'the two declarations are not the same statement');
    }

    public function testNothingElseInThisPackageSaysIt(): void
    {
        $this->needsAContractThatCanSayIt();

        $amending = array_keys(array_filter($this->operations(), static fn (Operation $operation): bool => $operation->amendsNamedTarget));

        self::assertSame(['edit'], $amending, 'an exception to the intent contract is declared one operation at a time');
    }

    public function testTheOperationsAreDeclaredWhateverTheInstalledContractCanSay(): void
    {
        $operations = $this->operations();

        self::assertArrayHasKey('edit', $operations);
        self::assertSame('class', $operations['edit']->namedTarget, 'the intent contract `edit` always had');
    }

    /** @return array<string, Operation> */
    private function operations(): array
    {
        return array_column((new DevToolsOperations())->operations(), null, 'name');
    }

    private function needsAContractThatCanSayIt(): void
    {
        if (!property_exists(Operation::class, 'amendsNamedTarget')) {
            self::markTestSkipped('The installed milpa/command cannot carry amendsNamedTarget (greenhouse decisions/0596): `edit` is declared as before, and the floor keeps asking.');
        }
    }
}
