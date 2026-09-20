<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

/** A rule read a fact the context does not carry. Absent is not null. */
final class MissingFact extends PrivacyException
{
}
