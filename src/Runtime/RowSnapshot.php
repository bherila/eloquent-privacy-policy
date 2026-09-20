<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Runtime;

use BWH\EloquentPrivacyPolicy\Exceptions\MissingAttribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw column values of one row. An absent column is an error, never NULL: a
 * partial select must not be mistaken for a row whose column is null.
 */
final readonly class RowSnapshot
{
    /** @param array<string, mixed> $attributes raw, uncast column values */
    public function __construct(private array $attributes)
    {
    }

    /** Current raw attributes. Use for rows that were just read. */
    public static function fromModel(Model $model): self
    {
        return new self($model->getAttributes());
    }

    /** Persisted raw attributes, ignoring unsaved changes. Use for action pre-state. */
    public static function fromPersisted(Model $model): self
    {
        return new self($model->getRawOriginal());
    }

    public function has(string $column): bool
    {
        return array_key_exists($column, $this->attributes);
    }

    public function get(string $column): mixed
    {
        if (! array_key_exists($column, $this->attributes)) {
            throw new MissingAttribute(sprintf(
                'Column "%s" is not part of this snapshot (partial select?). It is not treated as NULL.',
                $column,
            ));
        }

        return $this->attributes[$column];
    }
}
