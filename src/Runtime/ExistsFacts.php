<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Runtime;

use BWH\EloquentPrivacyPolicy\Predicate\Exists;
use BWH\EloquentPrivacyPolicy\Exceptions\MissingFact;

/**
 * Batch-prepared answers for the Exists nodes of one predicate over one batch
 * of rows. Operation-local; never shared or cached.
 */
final class ExistsFacts
{
    /** @var array<int, array<string, true>> spl_object_id(Exists) => set of matching outer key tuples */
    private array $matches = [];

    /** @var array<int, Exists> keeps the nodes alive so their object ids stay unique */
    private array $nodes = [];

    /** @param array<string, true> $tuples */
    public function record(Exists $node, array $tuples): void
    {
        $this->nodes[spl_object_id($node)] = $node;
        // Merged, not replaced: the same node object may be prepared for more than one batch.
        $this->matches[spl_object_id($node)] = ($this->matches[spl_object_id($node)] ?? []) + $tuples;
    }

    public function isPrepared(Exists $node): bool
    {
        return isset($this->nodes[spl_object_id($node)]);
    }

    public function holds(Exists $node, string $tuple): bool
    {
        if (! $this->isPrepared($node)) {
            throw new MissingFact(sprintf(
                'The EXISTS over "%s" was not batch-prepared. Relationship facts are never loaded per row.',
                $node->table,
            ));
        }

        return isset($this->matches[spl_object_id($node)][$tuple]);
    }
}
