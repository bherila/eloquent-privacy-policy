<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

use BWH\EloquentPrivacyPolicy\Exceptions\MissingFact;

/**
 * Who is asking. An anonymous viewer is an explicit value, not the absence of
 * a context.
 */
final readonly class Viewer
{
    private function __construct(
        private int|string|null $id,
        public string $type,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(null, 'anonymous');
    }

    public static function identified(int|string $id, string $type = 'user'): self
    {
        return new self($id, $type);
    }

    public function isAnonymous(): bool
    {
        return $this->id === null;
    }

    public function id(): int|string
    {
        return $this->id ?? throw new MissingFact('viewer.id: the viewer is anonymous');
    }

    public function intId(): int
    {
        $id = $this->id();

        if (is_int($id)) {
            return $id;
        }

        if (preg_match('/\A-?\d+\z/', $id) === 1) {
            return (int) $id;
        }

        throw new MissingFact('viewer.id is not an integer');
    }
}
