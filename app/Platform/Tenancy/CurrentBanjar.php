<?php

namespace App\Platform\Tenancy;

use LogicException;

final class CurrentBanjar
{
    private ?object $banjar = null;

    public function set(object $banjar): void
    {
        $this->banjar = $banjar;
    }

    public function clear(): void
    {
        $this->banjar = null;
    }

    public function optional(): ?object
    {
        return $this->banjar;
    }

    public function get(): object
    {
        return $this->banjar ?? throw new LogicException('Tenant context is required.');
    }

    public function id(): int
    {
        return (int) $this->get()->id;
    }

    public function cacheKey(string $key): string
    {
        return 'banjar:'.$this->id().':'.$key;
    }
}
