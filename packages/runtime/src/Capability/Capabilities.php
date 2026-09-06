<?php

declare(strict_types=1);

namespace Eleph\Runtime\Capability;

/**
 * What one adaptor can do.
 */
final readonly class Capabilities
{
    /** @var array<string, true> */
    private array $supported;

    public function __construct(public string $adaptor, Capability ...$capabilities)
    {
        $supported = [];

        foreach ($capabilities as $capability) {
            $supported[$capability->value] = true;
        }

        $this->supported = $supported;
    }

    public function supports(Capability $capability): bool
    {
        return isset($this->supported[$capability->value]);
    }

    /**
     * @throws UnsupportedCapability
     */
    public function require(Capability $capability): void
    {
        if (!$this->supports($capability)) {
            throw UnsupportedCapability::for($capability, $this->adaptor);
        }
    }

    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        $all = [];

        foreach (Capability::cases() as $capability) {
            if ($this->supports($capability)) {
                $all[] = $capability;
            }
        }

        return $all;
    }
}
