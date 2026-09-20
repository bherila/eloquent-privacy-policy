<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Policy;

use BWH\EloquentPrivacyPolicy\Exceptions\PolicyNotRegistered;
use BWH\EloquentPrivacyPolicy\Exceptions\UncompilablePolicy;
use Illuminate\Database\Eloquent\Model;

/** The policy of one model: a compilable read rule set and named action rule sets. */
final readonly class ModelPolicy
{
    /**
     * @param class-string<Model> $model
     * @param array<string, RuleSet> $actions
     */
    private function __construct(
        public string $model,
        private ?RuleSet $read,
        private array $actions,
    ) {
    }

    /** @param class-string<Model> $model */
    public static function for(string $model): self
    {
        return new self($model, null, []);
    }

    public function read(RuleSet $rules): self
    {
        if (! $rules->isCompilable()) {
            throw new UncompilablePolicy(sprintf(
                'The read policy of %s contains runtime-only rules. A collection policy must compile to SQL completely.',
                $this->model,
            ));
        }

        return new self($this->model, $rules, $this->actions);
    }

    public function action(string $name, RuleSet $rules): self
    {
        return new self($this->model, $this->read, [...$this->actions, $name => $rules]);
    }

    public function readRules(): RuleSet
    {
        return $this->read ?? throw new PolicyNotRegistered(sprintf('%s has no read policy.', $this->model));
    }

    public function actionRules(string $name): RuleSet
    {
        return $this->actions[$name] ?? throw new PolicyNotRegistered(sprintf(
            '%s has no policy for action "%s". A read policy never authorises a write.',
            $this->model,
            $name,
        ));
    }
}
