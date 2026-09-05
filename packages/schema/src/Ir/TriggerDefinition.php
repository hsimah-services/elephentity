<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * A side effect that runs as part of the commit.
 *
 * Declared in the entity spec and nowhere else. That is the whole difference between
 * this and WordPress hooks: if a trigger could be registered anywhere, reading the
 * entity's yaml would no longer tell you what a commit does. Execution order is
 * declaration order — deterministic, visible in the diff, and no priority-number
 * archaeology.
 */
final readonly class TriggerDefinition implements Contributed
{
    /**
     * @param list<TriggerEvent> $events
     */
    public function __construct(
        public string $name,
        public array $events,
        public Origin $origin,
        public TriggerPhase $phase = TriggerPhase::PreCommit,
        public ?string $description = null,
    ) {
    }

    public function firesOn(TriggerEvent $event): bool
    {
        return in_array($event, $this->events, true);
    }

    public function declaredIn(): Origin
    {
        return $this->origin;
    }
}
