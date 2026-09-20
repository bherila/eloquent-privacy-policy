<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Docs\Fixtures\Qa;

use BWH\EloquentPrivacyPolicy\Action\BaseAction;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends BaseAction<Question>
 */
final class QuestionEditAction extends BaseAction
{
    public function __construct(
        private readonly int $questionId,
        private readonly string $title,
    ) {
    }

    public function name(): string
    {
        return 'question.edit';
    }

    public function model(): string
    {
        return Question::class;
    }

    public function targetKey(): int
    {
        return $this->questionId;
    }

    public function changes(): array
    {
        return ['title' => $this->title];
    }

    /**
     * @param Question $target
     * @return Question
     */
    public function persist(Model $target, ConnectionInterface $connection): Model
    {
        $target->title = $this->title;
        $target->save();

        return $target;
    }
}
