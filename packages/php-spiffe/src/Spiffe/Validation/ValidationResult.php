<?php

declare(strict_types=1);

namespace Spiffe\Validation;

/**
 * Immutable result of a SPIFFE SVID validation operation.
 *
 * Captures success/failure along with all validation errors found,
 * allowing callers to inspect every issue rather than failing fast.
 */
final class ValidationResult
{
    private bool $valid;

    /** @var list<string> */
    private array $errors;

    /**
     * @param list<string> $errors
     */
    private function __construct(bool $valid, array $errors)
    {
        $this->valid = $valid;
        $this->errors = $errors;
    }

    public static function success(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $errors
     */
    public static function failure(array $errors): self
    {
        return new self(false, $errors);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<string> All validation errors found
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The first error message, or null if validation passed.
     */
    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Throw a RuntimeException if validation failed.
     *
     * @throws \RuntimeException
     */
    public function throwOnFailure(): void
    {
        if (!$this->valid) {
            throw new \RuntimeException(
                'SVID validation failed: ' . implode('; ', $this->errors)
            );
        }
    }

    public function __toString(): string
    {
        if ($this->valid) {
            return 'ValidationResult: PASS';
        }

        return 'ValidationResult: FAIL — ' . implode('; ', $this->errors);
    }
}
