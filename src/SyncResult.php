<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside;

use RefactorCircus\Ringside\Standards\Resolution;

final readonly class SyncResult
{
    /**
     * @param  list<string>  $written
     */
    public function __construct(public Resolution $resolution, public array $written) {}

    public function changed(): bool
    {
        return $this->written !== [];
    }

    public function summary(): string
    {
        $standards = count($this->resolution->standards);
        $packages = count($this->resolution->packageLayers());

        return $this->changed()
            ? sprintf('ringside: synced %d standards from %d package(s) + local; %d file(s) written.', $standards, $packages, count($this->written))
            : sprintf('ringside: %d standards up to date.', $standards);
    }
}
