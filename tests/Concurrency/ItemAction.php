<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

use BWH\EloquentPrivacyPolicy\Action\ActionFactProvider;
use BWH\EloquentPrivacyPolicy\Action\Anchor;
use BWH\EloquentPrivacyPolicy\Action\BaseAction;
use BWH\EloquentPrivacyPolicy\Action\ParentLink;
use BWH\EloquentPrivacyPolicy\Action\Receipt;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The action the racing child runs: anchored on its space, authorised by a
 * grant read under lock, writing one title.
 *
 * @extends BaseAction<Item>
 */
final class ItemAction extends BaseAction
{
    /** @param (Closure(Receipt): void)|null $after */
    public function __construct(
        private readonly ActionFactProvider $grants,
        private readonly ?Closure $after = null,
    ) {
    }

    public function name(): string
    {
        return Fixture::ACTION;
    }

    public function model(): string
    {
        return Item::class;
    }

    public function targetKey(): int
    {
        return Fixture::ITEM;
    }

    public function changes(): array
    {
        return ['title' => Fixture::WRITTEN];
    }

    public function anchors(): array
    {
        return [new Anchor(Fixture::SPACES, Fixture::SPACE)];
    }

    public function parent(): ParentLink
    {
        return ParentLink::of('space_id', Space::class);
    }

    public function facts(): ActionFactProvider
    {
        return $this->grants;
    }

    /**
     * @param Item $target
     * @return Item
     */
    public function persist(Model $target, ConnectionInterface $connection): Model
    {
        $target->title = Fixture::WRITTEN;
        $target->revision = $target->revision + 1;
        $target->save();

        return $target;
    }

    public function afterCommit(): ?Closure
    {
        return $this->after;
    }
}
