<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

/** Runtime evaluation needed a column the snapshot does not contain. */
final class MissingAttribute extends PrivacyException
{
}
