<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * Commit side effect executed in spec declaration order.
 */
final readonly class SideEffectDefinition implements Contributed
{
    /**
     * @param list<SideEffectEvent> $events
     */
    public function __construct(
        public string $name,
        public array $events,
        public Origin $origin,
        public SideEffectPhase $phase = SideEffectPhase::PreCommit,
        public ?string $description = null,
    ) {
    }

    public function firesOn(SideEffectEvent $event): bool
    {
        return in_array($event, $this->events, true);
    }

    public function declaredIn(): Origin
    {
        return $this->origin;
    }
}
