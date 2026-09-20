<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

/** No context was supplied. Distinct from an explicit anonymous context. */
final class MissingContext extends PrivacyException
{
}
