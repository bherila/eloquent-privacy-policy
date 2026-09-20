<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Tests\Concurrency;

/**
 * Contract 6.1, executed rather than asserted: a revoker and a writer in
 * separate processes, on separate connections, arranged into each of the two
 * interleavings the protocol claims — and one it does not claim.
 */
final class RevocationRaceTest extends ConcurrencyTestCase
{
    /**
     * Interleaving (a): the revoker takes the anchor first. The action reaches
     * the anchor while the revocation is still uncommitted, waits for it, and
     * then reads the grant that is no longer there.
     */
    public function test_a_revoker_that_takes_the_anchor_first_denies_the_action(): void
    {
        $revoker = $this->start('child_revoker.php', [
            'EMIT_INSIDE' => 'revoker_inside',
            'AWAIT_INSIDE' => 'action_started',
            // Held after the action is known to be under way, so that the
            // action can only get the anchor by waiting for this commit.
            'HOLD_MS' => 300,
        ]);

        $action = $this->start('child_action.php', [
            'AWAIT_BEFORE_START' => 'revoker_inside',
            'EMIT_BEFORE_START' => 'action_started',
        ]);

        $revoked = $this->finish($revoker, 'revoker');
        $written = $this->finish($action, 'action');

        $this->assertSame('denied', $written['outcome']);
        $this->assertSame('before', $this->title(), 'a denied action writes nothing');
        $this->assertSame(0, $this->grants());

        $this->assertSame('before', $revoked['title_when_anchored'], 'the revoker got there first');
        $this->assertGreaterThanOrEqual(
            200,
            $written['elapsed_ms'],
            'the action returned too quickly to have waited on the anchor',
        );

        $this->assertHappenedBefore('revoker_committed', 'action_denied');
    }

    /**
     * Interleaving (b): the action takes the anchor first. The revoker blocks
     * on the anchor until the action has committed, so the action is decided
     * on a grant that was still live when it was read, and the revocation
     * lands after the write rather than half-way through it.
     */
    public function test_an_action_that_takes_the_anchor_first_makes_the_revoker_wait(): void
    {
        $action = $this->start('child_action.php', [
            'EMIT_IN_FACTS' => 'action_anchored',
            'AWAIT_IN_FACTS' => 'revoker_attempting',
            'HOLD_MS' => 300,
        ]);

        $revoker = $this->start('child_revoker.php', [
            'AWAIT_BEFORE_START' => 'action_anchored',
            'EMIT_BEFORE_START' => 'revoker_attempting',
        ]);

        $written = $this->finish($action, 'action');
        $revoked = $this->finish($revoker, 'revoker');

        $this->assertSame('allowed', $written['outcome']);
        $this->assertSame(Fixture::WRITTEN, $this->title(), "the action's write is present");
        $this->assertSame(0, $this->grants(), 'and the revocation still landed, afterwards');

        $this->assertSame(
            Fixture::WRITTEN,
            $revoked['title_when_anchored'],
            'the revoker reached the anchor before the action had committed',
        );

        $this->assertGreaterThanOrEqual(
            200,
            $revoked['anchored_after_ms'],
            'the revoker did not wait for the anchor',
        );

        $this->assertHappenedBefore('action_anchored', 'revoker_attempting');
        $this->assertHappenedBefore('revoker_attempting', 'revoker_anchored');
        $this->assertHappenedBefore('action_committed', 'revoker_committed');
    }

    /**
     * The negative control. The same revocation from a writer that does not
     * take the anchor is not covered by anything: it walks past an anchor the
     * action is holding, commits in the middle of the action's transaction,
     * and changes the answer the action is about to read.
     *
     * If such a writer *were* covered, this arrangement could not finish at
     * all — the action is waiting for the writer that would be waiting for the
     * action.
     */
    public function test_a_grant_writer_that_takes_no_anchor_is_not_covered(): void
    {
        $action = $this->start('child_action.php', [
            'EMIT_IN_FACTS' => 'action_anchored',
            'AWAIT_IN_FACTS' => 'bystander_committed',
        ]);

        $bystander = $this->start('child_bystander.php', [
            'AWAIT_BEFORE_START' => 'action_anchored',
        ]);

        $ignored = $this->finish($bystander, 'bystander');
        $written = $this->finish($action, 'action');

        $this->assertLessThan(
            1_000,
            $ignored['elapsed_ms'],
            'the non-participating writer was blocked after all',
        );

        // It got in, so the action's authorisation changed underneath it —
        // here in the direction of denial, but the protocol makes no promise
        // either way for this writer.
        $this->assertSame('denied', $written['outcome']);
        $this->assertSame('before', $this->title());

        $this->assertHappenedBefore('action_anchored', 'bystander_committed');
        $this->assertHappenedBefore('bystander_committed', 'action_denied');
    }
}
