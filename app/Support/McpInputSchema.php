<?php

namespace App\Support;

class McpInputSchema
{
    private const VALID_TYPES = [
        'array' => true,
        'boolean' => true,
        'integer' => true,
        'null' => true,
        'number' => true,
        'object' => true,
        'string' => true,
    ];

    /** @return array<string, mixed> */
    public static function sanitizeDynamicCipp(mixed $schema): array
    {
        $sanitized = self::sanitizeSchemaNode($schema);
        if (! is_array($sanitized)) {
            $sanitized = [];
        }

        $sanitized['type'] = 'object';
        $sanitized['properties'] = self::sanitizeProperties($sanitized['properties'] ?? []);
        $sanitized['required'] = self::sanitizeRequired($sanitized['required'] ?? [], $sanitized['properties']);

        unset($sanitized['$schema']);

        return $sanitized;
    }

    /** @return array<int, string> */
    public static function validationErrors(mixed $schema): array
    {
        $errors = [];
        self::validateSchemaNode($schema, '$', $errors);

        if (is_array($schema)) {
            if (($schema['type'] ?? null) !== 'object') {
                $errors[] = '$.type must be object';
            }

            self::validatePropertiesKeyword($schema['properties'] ?? null, '$.properties', $errors);
        }

        return array_values(array_unique($errors));
    }

    private static function sanitizeSchemaNode(mixed $schema): array|\stdClass|bool
    {
        if (is_bool($schema)) {
            return $schema;
        }

        if ($schema instanceof \stdClass) {
            $schema = (array) $schema;
        }

        if (! is_array($schema)) {
            return new \stdClass;
        }

        if (array_is_list($schema)) {
            return new \stdClass;
        }

        $sanitized = [];

        foreach ($schema as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            match ($key) {
                '$schema' => null,
                'type' => self::sanitizeType($value, $sanitized),
                'properties' => $sanitized['properties'] = self::sanitizeProperties($value),
                'required' => null,
                'description', 'title', 'format', 'pattern' => is_string($value) && $value !== '' ? $sanitized[$key] = $value : null,
                'additionalProperties' => self::sanitizeAdditionalProperties($value, $sanitized),
                'items' => self::sanitizeItems($value, $sanitized),
                'enum' => is_array($value) && array_is_list($value) ? $sanitized['enum'] = array_values($value) : null,
                'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' => is_numeric($value) ? $sanitized[$key] = $value : null,
                'minLength', 'maxLength', 'minItems', 'maxItems', 'minProperties', 'maxProperties' => is_int($value) && $value >= 0 ? $sanitized[$key] = $value : null,
                'oneOf', 'anyOf', 'allOf' => self::sanitizeSchemaList($key, $value, $sanitized),
                'const', 'default', 'examples' => $sanitized[$key] = $value,
                default => null,
            };
        }

        if (array_key_exists('required', $schema)) {
            $sanitized['required'] = self::sanitizeRequired($schema['required'], $sanitized['properties'] ?? []);
        }

        return $sanitized === [] ? new \stdClass : $sanitized;
    }

    private static function sanitizeType(mixed $value, array &$schema): void
    {
        if (is_string($value) && isset(self::VALID_TYPES[$value])) {
            $schema['type'] = $value;

            return;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return;
        }

        $types = [];
        foreach ($value as $type) {
            if (is_string($type) && isset(self::VALID_TYPES[$type])) {
                $types[$type] = $type;
            }
        }

        if ($types !== []) {
            $schema['type'] = array_values($types);
        }
    }

    private static function sanitizeProperties(mixed $properties): array|\stdClass
    {
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }

        if (! is_array($properties) || array_is_list($properties)) {
            return new \stdClass;
        }

        $sanitized = [];
        foreach ($properties as $name => $schema) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $sanitized[$name] = self::sanitizeSchemaNode($schema);
        }

        return $sanitized === [] ? new \stdClass : $sanitized;
    }

    /** @return array<int, string> */
    private static function sanitizeRequired(mixed $required, mixed $properties): array
    {
        if (! is_array($required)) {
            return [];
        }

        $propertyNames = $properties instanceof \stdClass
            ? []
            : array_fill_keys(array_keys((array) $properties), true);

        $sanitized = [];
        foreach ($required as $field) {
            if (is_string($field) && isset($propertyNames[$field])) {
                $sanitized[$field] = $field;
            }
        }

        return array_values($sanitized);
    }

    private static function sanitizeAdditionalProperties(mixed $value, array &$schema): void
    {
        if (is_bool($value)) {
            $schema['additionalProperties'] = $value;

            return;
        }

        if (is_array($value) || $value instanceof \stdClass) {
            $schema['additionalProperties'] = self::sanitizeSchemaNode($value);
        }
    }

    private static function sanitizeItems(mixed $value, array &$schema): void
    {
        if (is_bool($value) || is_array($value) || $value instanceof \stdClass) {
            $schema['items'] = self::sanitizeSchemaNode($value);
        }
    }

    private static function sanitizeSchemaList(string $key, mixed $value, array &$schema): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return;
        }

        $items = [];
        foreach ($value as $item) {
            if (is_bool($item) || is_array($item) || $item instanceof \stdClass) {
                $items[] = self::sanitizeSchemaNode($item);
            }
        }

        if ($items !== []) {
            $schema[$key] = $items;
        }
    }

    /** @param  array<int, string>  $errors */
    private static function validateSchemaNode(mixed $schema, string $path, array &$errors): void
    {
        if (is_bool($schema)) {
            return;
        }

        $fromObject = $schema instanceof \stdClass;
        if ($fromObject) {
            $schema = (array) $schema;
        }

        if (! is_array($schema)) {
            $errors[] = "{$path} must be a schema object or boolean";

            return;
        }

        if (! $fromObject && array_is_list($schema)) {
            $errors[] = "{$path} must be a schema object, not an array";

            return;
        }

        if (array_key_exists('type', $schema) && ! self::validType($schema['type'])) {
            $errors[] = "{$path}.type is invalid";
        }

        if (array_key_exists('properties', $schema)) {
            self::validatePropertiesKeyword($schema['properties'], "{$path}.properties", $errors);
        }

        if (array_key_exists('required', $schema) && (! is_array($schema['required']) || ! array_is_list($schema['required']))) {
            $errors[] = "{$path}.required must be an array of strings";
        } elseif (isset($schema['required'])) {
            foreach ($schema['required'] as $index => $field) {
                if (! is_string($field) || $field === '') {
                    $errors[] = "{$path}.required.{$index} must be a non-empty string";
                }
            }
        }

        if (array_key_exists('additionalProperties', $schema)
            && ! is_bool($schema['additionalProperties'])
            && ! is_array($schema['additionalProperties'])
            && ! ($schema['additionalProperties'] instanceof \stdClass)) {
            $errors[] = "{$path}.additionalProperties must be a boolean or schema object";
        } elseif (array_key_exists('additionalProperties', $schema)
            && (is_array($schema['additionalProperties']) || $schema['additionalProperties'] instanceof \stdClass)) {
            self::validateSchemaNode($schema['additionalProperties'], "{$path}.additionalProperties", $errors);
        }

        if (array_key_exists('items', $schema)) {
            self::validateSchemaNode($schema['items'], "{$path}.items", $errors);
        }

        foreach (['description', 'title', 'format', 'pattern'] as $stringKeyword) {
            if (array_key_exists($stringKeyword, $schema) && ! is_string($schema[$stringKeyword])) {
                $errors[] = "{$path}.{$stringKeyword} must be a string";
            }
        }

        if (array_key_exists('enum', $schema) && (! is_array($schema['enum']) || ! array_is_list($schema['enum']))) {
            $errors[] = "{$path}.enum must be an array";
        }

        foreach (['oneOf', 'anyOf', 'allOf'] as $listKeyword) {
            if (! array_key_exists($listKeyword, $schema)) {
                continue;
            }

            if (! is_array($schema[$listKeyword]) || ! array_is_list($schema[$listKeyword])) {
                $errors[] = "{$path}.{$listKeyword} must be an array of schemas";

                continue;
            }

            foreach ($schema[$listKeyword] as $index => $item) {
                self::validateSchemaNode($item, "{$path}.{$listKeyword}.{$index}", $errors);
            }
        }
    }

    /** @param  array<int, string>  $errors */
    private static function validatePropertiesKeyword(mixed $properties, string $path, array &$errors): void
    {
        $fromObject = $properties instanceof \stdClass;
        if ($fromObject) {
            $properties = (array) $properties;
        }

        if (! is_array($properties) || (! $fromObject && array_is_list($properties))) {
            $errors[] = "{$path} must be an object";

            return;
        }

        foreach ($properties as $name => $schema) {
            if (! is_string($name) || $name === '') {
                $errors[] = "{$path} keys must be non-empty strings";

                continue;
            }

            self::validateSchemaNode($schema, "{$path}.{$name}", $errors);
        }
    }

    private static function validType(mixed $type): bool
    {
        if (is_string($type)) {
            return isset(self::VALID_TYPES[$type]);
        }

        if (! is_array($type) || ! array_is_list($type) || $type === []) {
            return false;
        }

        foreach ($type as $item) {
            if (! is_string($item) || ! isset(self::VALID_TYPES[$item])) {
                return false;
            }
        }

        return count($type) === count(array_unique($type));
    }
}
