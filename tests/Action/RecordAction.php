<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Action;

use BWH\EloquentPrivacyPolicy\Action\Action;
use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\ActionInput;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\BaseAction;
use BWH\EloquentPrivacyPolicy\Action\ParentLink;
use BWH\EloquentPrivacyPolicy\Action\Receipt;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One configurable action over {@see Record}, so a test states only the part it
 * is about. Fluent and mutable on purpose: it is a fixture, not an example of
 * how an application should write one.
 *
 * @extends BaseAction<Record>
 */
final class RecordAction extends BaseAction
{
    /** @var list<Anchor> */
    private array $anchors = [];

    /** @var ParentLink<Workspace>|null */
    private ?ParentLink $link = null;

    private ?ActionFactProvider $facts = null;

    /** @var (Closure(ActionInput): void)|null */
    private ?Closure $validator = null;

    /** @var (Closure(Receipt): void)|null */
    private ?Closure $after = null;

    /** @var (Closure(Record): void)|null */
    private ?Closure $whilePersisting = null;

    /**
     * @param array<string, mixed> $changes
     */
    private function __construct(
        private readonly string $action,
        private readonly int|string|null $key,
        private readonly array $changes,
    ) {
    }

    /** @param array<string, mixed> $changes */
    public static function update(int|string $key, array $changes, string $action = 'record.update'): self
    {
        return new self($action, $key, $changes);
    }

    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes, string $action = 'record.create'): self
    {
        return new self($action, null, $attributes);
    }

    public function anchoredOn(Anchor ...$anchors): self
    {
        $this->anchors = array_values($anchors);

        return $this;
    }

    /** @param ParentLink<Workspace> $link */
    public function under(ParentLink $link): self
    {
        $this->link = $link;

        return $this;
    }

    public function reading(ActionFactProvider $facts): self
    {
        $this->facts = $facts;

        return $this;
    }

    /** @param Closure(ActionInput): void $validator */
    public function validatedBy(Closure $validator): self
    {
        $this->validator = $validator;

        return $this;
    }

    /** @param Closure(Receipt): void $callback */
    public function thenAfterCommit(Closure $callback): self
    {
        $this->after = $callback;

        return $this;
    }

    /** @param Closure(Record): void $hook runs inside the transaction, just before the save */
    public function whilePersisting(Closure $hook): self
    {
        $this->whilePersisting = $hook;

        return $this;
    }

    public function name(): string
    {
        return $this->action;
    }

    public function model(): string
    {
        return Record::class;
    }

    public function targetKey(): int|string|null
    {
        return $this->key;
    }

    public function changes(): array
    {
        return $this->changes;
    }

    public function anchors(): array
    {
        return $this->anchors;
    }

    public function parent(): ?ParentLink
    {
        return $this->link;
    }

    public function facts(): ?ActionFactProvider
    {
        return $this->facts;
    }

    public function validate(ActionInput $input): void
    {
        ($this->validator ?? static fn (ActionInput $i): null => null)($input);
    }

    /**
     * @param Record $target
     * @return Record
     */
    public function persist(Model $target, ConnectionInterface $connection): Model
    {
        $target->forceFill($this->changes);
        $target->revision = ($target->exists ? $target->revision : 0) + 1;

        ($this->whilePersisting ?? static fn (Record $r): null => null)($target);

        $target->save();

        return $target;
    }

    /** @param Record $persisted */
    public function version(Model $persisted): int
    {
        return $persisted->revision;
    }

    public function afterCommit(): ?Closure
    {
        return $this->after;
    }
}
