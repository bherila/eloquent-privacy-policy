<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

use RuntimeException;

/**
 * Base class for every failure raised by the package. A privacy failure is
 * never converted into a Skip or an implicit Allow.
 */
abstract class PrivacyException extends RuntimeException
{
}
