<?php

namespace LaravelTelescope\Telemetry\Contracts;

interface ToolInterface
{
    /**
     * Get the tool name.
     */
    public function getName(): string;
    
    /**
     * Get the tool description.
     */
    public function getDescription(): string;
    
    /**
     * Get the tool's input schema.
     */
    public function getInputSchema(): array;
    
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
