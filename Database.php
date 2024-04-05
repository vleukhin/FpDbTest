<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    /**
     * This value is used skip conditional parts of the query
     */
    const SKIP_VALUE = '%SKIP%';
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Get skip value
     *
     * @return string
     */
    public function skip(): string
    {
        return self::SKIP_VALUE;
    }

    /**
     * Build given query with args
     *
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $query = $this->replacePlaceholders($query, $args);

        return $this->processConditionalParts($query);
    }

    /**
     * Here we extract all placeholders from query string
     *
     * @return array<QueryPlaceholder>
     */
    protected function extractPlaceholders(string $query): array
    {
        $result = [];
        $template = '(';
        foreach (QueryPlaceholder::cases() as $placeholder) {
            $template .= "\\$placeholder->value|";
        }
        $template = substr($template, 0, -1) . ')';
        preg_match_all("@$template@", $query, $matches);
        foreach ($matches[0] ?? [] as $placeholder) {
            $result[] = QueryPlaceholder::tryFrom($placeholder);
        }

        return array_filter($result);
    }

    /**
     * Replaces placeholders in query string
     *
     * @param string $query
     * @param array $args
     *
     * @return string
     * @throws Exception
     */
    protected function replacePlaceholders(string $query, array $args): string
    {
        // Get rid of possible keys to match placeholders and args by index
        $args = array_values($args);
        $placeholders = $this->extractPlaceholders($query);
        if (count($placeholders) !== count($args)) {
            throw new Exception(sprintf("Wrong arguments count, expect: %d, got: %d", count($placeholders), count($args)));
        }

        foreach ($placeholders as $index => $placeholder) {
            $query = $this->replace($query, $placeholder, $args[$index]);
        }

        return $query;
    }

    /**
     * Replace particular placeholder in query string
     *
     * @throws Exception
     */
    protected function replace(string $query, QueryPlaceholder $placeholder, $arg): string
    {
        $value = $this->getValue($placeholder, $arg);

        return preg_replace("@\\$placeholder->value@", $value, $query, 1);
    }

    /**
     * Get arg value according to given placeholder
     *
     * @throws Exception
     */
    protected function getValue(QueryPlaceholder $placeholder, $arg): string
    {
        if (is_null($arg) && $placeholder->nullable()) {
            return 'NULL';
        }
        if ($arg === self::SKIP_VALUE) {
            return $arg;
        }
        return match ($placeholder) {
            QueryPlaceholder::Int => $this->getIntValue($arg),
            QueryPlaceholder::Float => $this->getFloatValue($arg),
            QueryPlaceholder::Array => $this->getArrayValue($arg),
            QueryPlaceholder::Column => $this->getColumnValue($arg),
            QueryPlaceholder::Common => $this->getCommonValue($arg),
        };
    }

    /**
     * Try to convert arg to int value
     *
     * @throws Exception
     */
    protected function getIntValue($arg): string {
        if (is_bool($arg)) {
            return $arg ? '1' : '0';
        }
        if (!is_numeric($arg)) {
            throw new Exception("Wrong int arg value");
        }

        return (string)(int)$arg;
    }

    /**
     * Try to convert arg to float value
     *
     * @throws Exception
     */
    protected function getFloatValue($arg): string {
        if (is_bool($arg)) {
            return $arg ? '1' : '0';
        }
        if (!is_numeric($arg)) {
            throw new Exception("Wrong float arg value");
        }

        return (string)(float)$arg;
    }

    /**
     * Try to convert arg to array value
     * It could be a list of values "1,2,3"
     * or key - value pairs "column1 = 1, column2 = 2"
     *
     * @throws Exception
     */
    protected function getArrayValue($arg): string {
        if (!is_array($arg)) {
            throw new Exception("Wrong array arg value");
        }
        $assoc = array_values($arg) !== $arg;
        $result = '';
        foreach ($arg as $index => $item) {
            $value = $this->getCommonValue($item);
            if ($assoc) {
                $result .= ($this->escape($index, true) . ' = ' . $value);
            } else {
                $result .= $value;
            }

            $result .= ', ';
        }

        return substr($result, 0, -2);
    }

    /**
     * Try to convert arg to column value
     *
     * @throws Exception
     */
    public function getColumnValue($arg): string
    {
        if (!is_array($arg)) {
            $arg = [$arg];
        }

        return implode(', ', array_map(fn($v) => "{$this->escape($v, true)}", $arg));
    }

    /**
     * Try to detect type of arg and convert to corresponding value
     *
     * @throws Exception
     */
    protected function getCommonValue($arg): string
    {
        if (is_null($arg)) {
            return 'NULL';
        }
        if (is_numeric($arg)) {
            if (is_int($arg)) {
                return $this->getIntValue($arg);
            }
            if (is_float($arg)) {
                return $this->getFloatValue($arg);
            }
        }

        if (is_string($arg)) {
            return $this->escape($arg, false);
        }

        throw new Exception("Wrong arg value");
    }

    /**
     * Escapes string
     *
     * @param string $string
     * @param bool $column
     *
     * @return string
     */
    protected function escape(string $string, bool $column): string
    {
        $string = mysqli_escape_string($this->mysqli, $string);

        return $column ? "`$string`" : "'$string'";
    }

    /**
     * Unwrap or remove conditional parts of the query
     *
     * @param string $query
     * @return string
     */
    protected function processConditionalParts(string $query): string
    {
        preg_match('@({.*})@', $query, $matches);
        foreach ($matches as $match) {
            if (str_contains($match, self::SKIP_VALUE)) {
                $query = str_replace($match, '', $query);
            } else {
                $unwrapped = str_replace('{', '', $match);
                $unwrapped = str_replace('}', '', $unwrapped);
                $query = str_replace($match, $unwrapped, $query);
            }
        }

        return $query;
    }
}
