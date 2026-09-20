<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

/** A value cannot be normalised to the column's declared type. */
final class AttributeTypeMismatch extends PrivacyException
{
}
