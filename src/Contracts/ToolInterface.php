<?php

declare(strict_types=1);

namespace Skylence\TelescopeMcp\Contracts;

interface ToolInterface
{
    /**
     * Get the tool's short name (used as identifier).
     */
    public function getShortName(): string;

    /**
     * Get the tool's full name.
     */
    public function getName(): string;

    /**
     * Get the tool's schema definition.
     *
     * @return array{name: string, description: string, inputSchema: array}
     */
    public function getSchema(): array;

    /**
     * Execute the tool with given arguments.
     */
    public function execute(array $arguments = []): array;

    /**
     * Get summary view of the data.
     */
    public function summary(array $arguments = []): array;

    /**
     * Get list view of the data.
     */
    public function list(array $arguments = []): array;

    /**
     * Get detailed view of a single item.
     */
    public function detail(string $id, array $arguments = []): array;

    /**
     * Get statistics about the data.
     */
    public function stats(array $arguments = []): array;
}
