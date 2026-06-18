<?php

namespace App\Enums;

class Color
{
    // Basic Colors - THIS SYNTAX IS CORRECT FOR PHP 7.1+
    public const RED = 'red';
    public const BLUE = 'blue'; 
    public const GREEN = 'green';
    public const BLACK = 'black';
    public const WHITE = 'white';
    public const YELLOW = 'yellow';
    public const PURPLE = 'purple';
    public const ORANGE = 'orange';
    public const PINK = 'pink';
    public const BROWN = 'brown';
    public const GRAY = 'gray';
    public const NAVY = 'navy';

    public string $value;
    
    // Store all colors
    private static $all = [
        self::RED, self::BLUE, self::GREEN, self::BLACK, self::WHITE,
        self::YELLOW, self::PURPLE, self::ORANGE, self::PINK, self::BROWN,
        self::GRAY, self::NAVY,
    ];
    
    // Store display names
    private static $displayNames = [
        self::RED => 'Red',
        self::BLUE => 'Blue',
        self::GREEN => 'Green',
        self::BLACK => 'Black',
        self::WHITE => 'White',
        self::YELLOW => 'Yellow',
        self::PURPLE => 'Purple',
        self::ORANGE => 'Orange',
        self::PINK => 'Pink',
        self::BROWN => 'Brown',
        self::GRAY => 'Gray',
        self::NAVY => 'Navy',
    ];
    
    // Store hex codes
    private static $hexCodes = [
        self::RED => '#FF0000',
        self::BLUE => '#0000FF',
        self::GREEN => '#008000',
        self::BLACK => '#000000',
        self::WHITE => '#FFFFFF',
        self::YELLOW => '#FFFF00',
        self::PURPLE => '#800080',
        self::ORANGE => '#FFA500',
        self::PINK => '#FFC0CB',
        self::BROWN => '#A52A2A',
        self::GRAY => '#808080',
        self::NAVY => '#000080',
    ];
    
    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function getAll(): array
    {
        return self::$all;
    }
    
    public static function isValid($color)
    {
        $value = self::valueOf($color);
        return $value !== null && in_array($value, self::$all, true);
    }
    
    public static function getDisplayName($color)
    {
        $value = self::valueOf($color);
        if ($value === null) {
            return '';
        }

        return isset(self::$displayNames[$value]) ? self::$displayNames[$value] : ucfirst($value);
    }
    
    public static function getHexCode($color)
    {
        $value = self::valueOf($color);
        return $value !== null && isset(self::$hexCodes[$value]) ? self::$hexCodes[$value] : '#CCCCCC';
    }
    
    public static function tryFrom($value): ?self
    {
        return self::isValid($value) ? self::from($value) : null;
    }
    
    public static function from($value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!self::isValid($value)) {
            $color = is_scalar($value) ? (string) $value : get_debug_type($value);
            throw new \InvalidArgumentException("Invalid color: {$color}");
        }

        return new self((string) $value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function valueOf($color): ?string
    {
        if ($color instanceof self) {
            return $color->value;
        }

        return is_string($color) ? $color : null;
    }
}
