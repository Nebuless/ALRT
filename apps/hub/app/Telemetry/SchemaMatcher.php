<?php

namespace App\Telemetry;

use DateTimeImmutable;
use stdClass;

class SchemaMatcher
{
    /** @param array<string, mixed> $schema */
    public function __construct(private readonly array $schema) {}

    /** @param array<string, mixed>|null $rule */
    public function matches(mixed $value, ?array $rule = null): bool
    {
        $rule ??= $this->schema;
        if (isset($rule['$ref'])) {
            return $this->matches($value, $this->schema['$defs'][substr($rule['$ref'], strlen('#/$defs/'))]);
        }
        if (isset($rule['type'])) {
            $validType = match ($rule['type']) {
                'object' => $value instanceof stdClass,
                'array' => is_array($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if (! $validType) {
                return false;
            }
        }
        if (array_key_exists('const', $rule) && $value !== $rule['const']) {
            return false;
        }
        if (isset($rule['enum']) && ! in_array($value, $rule['enum'], true)) {
            return false;
        }
        foreach (['minimum', 'maximum', 'exclusiveMinimum'] as $bound) {
            if (isset($rule[$bound]) && match ($bound) {
                'minimum' => $value < $rule[$bound],
                'maximum' => $value > $rule[$bound],
                'exclusiveMinimum' => $value <= $rule[$bound],
            }) {
                return false;
            }
        }
        if (is_string($value)) {
            if (isset($rule['maxLength']) && mb_strlen($value) > $rule['maxLength']) {
                return false;
            }
            if (isset($rule['pattern']) && preg_match('~'.$rule['pattern'].'~Du', $value) !== 1) {
                return false;
            }
            if (($rule['format'] ?? null) === 'date-time' && ! $this->isTimestamp($value)) {
                return false;
            }
        }
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            foreach ($rule['required'] ?? [] as $required) {
                if (! array_key_exists($required, $properties)) {
                    return false;
                }
            }
            if (($rule['additionalProperties'] ?? true) === false && array_diff_key($properties, $rule['properties'] ?? []) !== []) {
                return false;
            }
            foreach ($rule['properties'] ?? [] as $key => $child) {
                if (array_key_exists($key, $properties) && ! $this->matches($properties[$key], $child)) {
                    return false;
                }
            }
        }
        if (is_array($value)) {
            if (isset($rule['maxItems']) && count($value) > $rule['maxItems']) {
                return false;
            }
            foreach ($value as $item) {
                if (isset($rule['items']) && ! $this->matches($item, $rule['items'])) {
                    return false;
                }
            }
        }
        if (isset($rule['anyOf'])) {
            $matched = false;
            foreach ($rule['anyOf'] as $alternative) {
                $matched = $matched || $this->matches($value, $alternative);
            }
            if (! $matched) {
                return false;
            }
        }
        foreach ($rule['allOf'] ?? [] as $condition) {
            $branch = $this->matches($value, $condition['if']) ? 'then' : 'else';
            if (! $this->matches($value, $condition[$branch])) {
                return false;
            }
        }

        return true;
    }

    private function isTimestamp(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[Zz]|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return false;
        }
        try {
            new DateTimeImmutable($value);

            return DateTimeImmutable::getLastErrors() === false;
        } catch (\Exception) {
            return false;
        }
    }
}
