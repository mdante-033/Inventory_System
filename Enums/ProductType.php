<?php

namespace App\Enums;

class ProductType
{
    public const SHOE = 'shoe';
    public const CLOTHING = 'clothing';

    public string $value;
    
    private static array $all = [
        self::SHOE,
        self::CLOTHING,
    ];
    
    public static function getAll(): array
    {
        return self::$all;
    }
    
    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function isValid($type): bool
    {
        $value = self::valueOf($type);
        return $value !== null && in_array($value, self::$all, true);
    }

    public static function from($value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!self::isValid($value)) {
            $type = is_scalar($value) ? (string) $value : get_debug_type($value);
            throw new \InvalidArgumentException("Invalid product type: {$type}");
        }

        return new self((string) $value);
    }

    public static function tryFrom($value): ?self
    {
        return self::isValid($value) ? self::from($value) : null;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function valueOf($type): ?string
    {
        if ($type instanceof self) {
            return $type->value;
        }

        return is_string($type) ? $type : null;
    }
}
