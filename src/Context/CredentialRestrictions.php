<?php

declare(strict_types=1);

namespace BWH\EloquentPrivacyPolicy\Context;

/**
 * Restrictions carried by the credential (token abilities, for example). They
 * narrow what the viewer may do; they never add to it.
 */
final readonly class CredentialRestrictions
{
    /** @param list<string>|null $abilities null means the credential imposes no restriction */
    private function __construct(private ?array $abilities)
    {
    }

    public static function unrestricted(): self
    {
        return new self(null);
    }

    /** @param list<string> $abilities */
    public static function only(array $abilities): self
    {
        return new self(array_values(array_unique($abilities)));
    }

    public function isUnrestricted(): bool
    {
        return $this->abilities === null;
    }

    public function permits(string $ability): bool
    {
        return $this->abilities === null || in_array($ability, $this->abilities, true);
    }
}
