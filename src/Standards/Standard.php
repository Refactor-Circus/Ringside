<?php

declare(strict_types=1);

namespace RefactorCircus\Ringside\Standards;

final readonly class Standard
{
    public const string DEFAULT_KIND = 'convention';

    /** @var list<string> */
    public const array KINDS = ['architectural', 'convention', 'tooling'];

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $enforcedBy
     */
    public function __construct(
        public string $key,
        public string $path,
        public string $source,
        public string $description,
        public array $tags = [],
        public array $enforcedBy = [],
        public ?string $overrides = null,
        public ?string $overriddenPath = null,
        public ?string $reviewedHash = null,
        public ?string $kind = null,
    ) {}

    public function category(): string
    {
        return str_contains($this->key, '/') ? explode('/', $this->key, 2)[0] : 'root';
    }

    public function isLocal(): bool
    {
        return $this->source === 'local';
    }

    public function kind(): string
    {
        return $this->kind ?? self::DEFAULT_KIND;
    }

    public function isArchitectural(): bool
    {
        return $this->kind() === 'architectural';
    }

    /**
     * This standard, overriding the one it shadows: empty fields inherit
     * from the overridden standard.
     */
    public function overriding(Standard $vendor, ?string $reviewedHash): self
    {
        return $this->with(
            description: $this->description !== '' ? $this->description : $vendor->description,
            tags: $this->tags !== [] ? $this->tags : $vendor->tags,
            enforcedBy: $this->enforcedBy !== [] ? $this->enforcedBy : $vendor->enforcedBy,
            overrides: $vendor->source,
            overriddenPath: $vendor->path,
            reviewedHash: $reviewedHash,
            kind: $this->kind ?? $vendor->kind,
        );
    }

    /**
     * @param  list<string>|null  $tags
     * @param  list<string>|null  $enforcedBy
     */
    public function with(
        ?string $description = null,
        ?array $tags = null,
        ?array $enforcedBy = null,
        ?string $overrides = null,
        ?string $overriddenPath = null,
        ?string $reviewedHash = null,
        ?string $kind = null,
    ): self {
        return new self(
            key: $this->key,
            path: $this->path,
            source: $this->source,
            description: $description ?? $this->description,
            tags: $tags ?? $this->tags,
            enforcedBy: $enforcedBy ?? $this->enforcedBy,
            overrides: $overrides ?? $this->overrides,
            overriddenPath: $overriddenPath ?? $this->overriddenPath,
            reviewedHash: $reviewedHash ?? $this->reviewedHash,
            kind: $kind ?? $this->kind,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toIndex(): array
    {
        return array_filter([
            'path' => $this->path,
            'source' => $this->source,
            'overrides' => $this->overrides,
            'category' => $this->category(),
            'kind' => $this->kind(),
            'description' => $this->description,
            'tags' => $this->tags,
            'enforced_by' => $this->enforcedBy,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
