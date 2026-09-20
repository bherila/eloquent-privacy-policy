<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy;

enum Decision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Skip = 'skip';
}
