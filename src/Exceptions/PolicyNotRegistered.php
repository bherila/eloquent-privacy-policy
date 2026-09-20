<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

/** A protected path reached a model that has no policy. */
final class PolicyNotRegistered extends PrivacyException
{
}
