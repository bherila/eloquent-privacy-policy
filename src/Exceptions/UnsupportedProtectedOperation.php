<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Exceptions;

/** A protected builder or model was asked for something outside the supported matrix. */
final class UnsupportedProtectedOperation extends PrivacyException
{
}
