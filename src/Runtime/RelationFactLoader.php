<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Runtime;

use BWH\EloquentPrivacyPolicy\Predicate\AllOf;
use BWH\EloquentPrivacyPolicy\Predicate\AnyOf;
use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Predicate\Negation;
use BWH\EloquentPrivacyPolicy\Predicate\Predicate;
use Illuminate\Database\ConnectionInterface;

/**
 * Batch-prepares the relationship facts a predicate needs for a set of rows:
 * one bounded query per Exists node per batch (chunked by key), then the inner
 * predicate is evaluated in PHP. The query count depends on the shape of the
 * policy, never on the number of rows.
 */
final class RelationFactLoader
{
    private const int CHUNK = 1000;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PredicateEvaluator $evaluator = new PredicateEvaluator(),
    ) {
    }

    /** @param list<RowSnapshot> $rows */
    public function prepare(Predicate $predicate, array $rows, ?ExistsFacts $facts = null): ExistsFacts
    {
        $facts ??= new ExistsFacts();

        foreach ($this->existsNodes($predicate) as $node) {
            $this->prepareNode($node, $rows, $facts);
        }

        return $facts;
    }

    /** @param list<RowSnapshot> $outerRows */
    private function prepareNode(Exists $node, array $outerRows, ExistsFacts $facts): void
    {
        [$firstOuter, $firstInner] = $node->matches[0];

        $keys = [];

        foreach ($outerRows as $row) {
            $value = $this->evaluator->value($firstOuter, $row);

            if ($value !== null) {
                $keys[(string) $firstOuter->type->binding($value)] = $firstOuter->type->binding($value);
            }
        }

        $innerRows = [];

        foreach (array_chunk(array_values($keys), self::CHUNK) as $chunk) {
            // Candidate rows only. The database may match more loosely than the
            // IR (collations); the exact match is decided below, in PHP.
            foreach ($this->connection->table($node->table)->whereIn($firstInner->name, $chunk)->get() as $inner) {
                $innerRows[] = new RowSnapshot((array) $inner);
            }
        }

        // Nested Exists nodes are prepared for the whole inner batch first.
        $this->prepare($node->where, $innerRows, $facts);

        $matching = [];

        foreach ($innerRows as $inner) {
            $tuple = $this->evaluator->innerTuple($node, $inner);

            if ($tuple !== null && $this->evaluator->evaluate($node->where, $inner, $facts)) {
                $matching[$tuple] = true;
            }
        }

        $facts->record($node, $matching);
    }

    /** @return list<Exists> the outermost Exists nodes of a predicate */
    private function existsNodes(Predicate $predicate): array
    {
        return match (true) {
            $predicate instanceof Exists => [$predicate],
            $predicate instanceof Negation => $this->existsNodes($predicate->inner),
            $predicate instanceof AllOf, $predicate instanceof AnyOf => array_merge(
                ...array_map(fn (Predicate $child): array => $this->existsNodes($child), $predicate->predicates),
            ),
            default => [],
        };
    }
}
