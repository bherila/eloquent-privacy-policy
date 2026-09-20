<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

/** A collection policy contains something the SQL compiler cannot express. */
final class UncompilablePolicy extends PrivacyException
{
}
