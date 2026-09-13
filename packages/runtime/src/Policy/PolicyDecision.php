<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

final readonly class PolicyDecision
{
    private function __construct(
        public PolicyOutcome $outcome,
        public string $reason,
        public ?string $policy = null,
    ) {
    }

    public static function allow(string $reason = ''): self
    {
        return new self(PolicyOutcome::Allow, $reason);
    }

    public static function skip(string $reason = ''): self
    {
        return new self(PolicyOutcome::Skip, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(PolicyOutcome::Deny, $reason);
    }

    public function withPolicy(string $name): self
    {
        return new self($this->outcome, $this->reason, $name);
    }
}
