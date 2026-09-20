<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Immutable snapshot of who is asking, in what capacity, within which resource
 * boundary, under which credential restrictions, for which operation.
 */
final readonly class PrivacyContext
{
    public ResourceScope $scope;

    public CredentialRestrictions $restrictions;

    public Facts $facts;

    /** The single clock both interpreters use, in UTC, truncated to whole seconds. */
    public DateTimeImmutable $now;

    public function __construct(
        public Viewer $viewer,
        public Operation $operation,
        ?ResourceScope $scope = null,
        public ?Capacity $capacity = null,
        ?CredentialRestrictions $restrictions = null,
        ?Facts $facts = null,
        ?DateTimeInterface $now = null,
    ) {
        $this->scope = $scope ?? new ResourceScope();
        $this->restrictions = $restrictions ?? CredentialRestrictions::unrestricted();
        $this->facts = $facts ?? new Facts();

        $utc = ($now === null ? new DateTimeImmutable('now') : DateTimeImmutable::createFromInterface($now))
            ->setTimezone(new DateTimeZone('UTC'));

        // setTime() with three arguments also zeroes the microseconds.
        $this->now = $utc->setTime((int) $utc->format('G'), (int) $utc->format('i'), (int) $utc->format('s'));
    }

    public function fact(string $key): mixed
    {
        return $this->facts->get($key);
    }

    /** @param array<string, mixed> $facts */
    public function withFacts(array $facts): self
    {
        return new self(
            $this->viewer,
            $this->operation,
            $this->scope,
            $this->capacity,
            $this->restrictions,
            $this->facts->with($facts),
            $this->now,
        );
    }

    public function forOperation(string $name): self
    {
        return new self(
            $this->viewer,
            new Operation($name),
            $this->scope,
            $this->capacity,
            $this->restrictions,
            $this->facts,
            $this->now,
        );
    }
}
