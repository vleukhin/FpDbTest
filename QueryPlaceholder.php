<?php
namespace FpDbTest;

enum QueryPlaceholder: string
{
    case Int = '?d';
    case Float = '?f';
    case Array = '?a';
    case Column = '?#';
    case Common = '?';

    public function nullable(): bool
    {
        return in_array($this, [
            self::Common,
            self::Int,
            self::Float,
        ]);
    }
}