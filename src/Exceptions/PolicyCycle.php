<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

/** ViaParent expansion revisited a model already on the expansion stack. */
final class PolicyCycle extends PrivacyException
{
}
