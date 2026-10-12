<?php

declare(strict_types=1);

namespace GigEconomics\Domain\Quality;

use JsonSerializable;

/**
 * A single data-quality finding for one refuelling row.
 */
final class DataQualityIssue implements JsonSerializable
{
    public function __construct(
        public readonly DataQualityIssueType $type,
        public readonly Severity $severity,
        public readonly string $message,
        public readonly ?int $rowIndex = null,
        public readonly ?string $reference = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'row_index' => $this->rowIndex,
            'reference' => $this->reference,
        ];
    }
}
