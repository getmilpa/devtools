<?php

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

/** The full run of a {@see BoundaryValidator}: one {@see BoundaryRuleResult} per rule checked. */
final class BoundaryReport
{
    /** @param list<BoundaryRuleResult> $results */
    public function __construct(public readonly array $results)
    {
    }

    /** True when every rule in {@see self::$results} passed. */
    public function ok(): bool
    {
        foreach ($this->results as $result) {
            if (!$result->ok()) {
                return false;
            }
        }

        return true;
    }

    /** Sum of violations across every rule in {@see self::$results}. */
    public function totalViolations(): int
    {
        $total = 0;
        foreach ($this->results as $result) {
            $total += count($result->violations);
        }

        return $total;
    }
}
