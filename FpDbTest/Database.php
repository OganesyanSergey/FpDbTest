<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    private string | null $query = null;

    private array $defaultAllowedType = ['string', 'integer', 'double', 'boolean', 'NULL'];


    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    private function isSequential(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * @throws Exception
     */
    private function validateAndGetSpecifierReplaceValue($specifier, $param): string | null
    {
        try {
            $type = gettype($param);
            switch ($specifier) {
                case '?':
                    $allowedTypes = $this->defaultAllowedType;
                    $replaceVal = "'$param'";
                    break;
                case '?d':
                    $allowedTypes = ['string', 'integer', 'boolean', 'NULL'];
                    $replaceVal = $type === 'boolean' ? (int)$param : $param;
                    break;
                case '?f':
                    $allowedTypes = ['string', 'double', 'NULL'];
                    $replaceVal = (float)$param;
                    break;
                case '?#':
                    $allowedTypes = ['string', 'array'];
                    $replaceVal = $type === 'string' ? "`$param`" : "`".implode("`, `", $param)."`";
                    break;
                case '?a':
                    $allowedTypes = ['string', 'array'];
                    if ($this->isSequential($param)) {
                        if ($param[0] === 'string') {
                            $replaceVal = "`".implode("`, `", $param)."`";
                        } else {
                            $replaceVal = implode(", ", $param);
                        }
                    } else {
                        $replaceVal = "";
                        $count = 0;
                        foreach ($param as $key => $value) {
                            $count += 1;
                            $isLastItem = $count === count($param);
                            $useValue = $value ? "'$value'" : 'NULL';
                            $useValue = !$isLastItem ? "$useValue, " : $useValue;
                            $replaceVal .= "`$key` = $useValue";
                        }
                    }

                    break;
                default:
                    return $param;
            }
        } catch (\Exception $exception) {
            throw new \Exception("Type of $specifier is not allowed");
        }

        if (
            !in_array($type, $allowedTypes)
            || (
                $type === 'string'
                && $param !== 'SKIP_BLOCK'
                && in_array($specifier, ['?f', '?a'])
            )
        )
            throw new \Exception("Type of $specifier is not allowed");

        return $replaceVal ?? NULL;
    }

    /**
     * @throws Exception
     */
    private function replaceFirstSpecifierAccuracy($string, $replaceWith): string | null
    {
        try {
            $symbolPosition = mb_strpos($string, '?');
            if (!$symbolPosition)
                return NULL;

            $specifier = substr($string, $symbolPosition, 2);
            $specifier = $specifier === '? ' ? '?' : $specifier;
            $replaceVal = $this->validateAndGetSpecifierReplaceValue($specifier, $replaceWith);

            if (strlen($specifier) === 2)
                $string = substr_replace($string, '', $symbolPosition + 1, 1);

            $blockStartPos = mb_strpos($string, '{');
            $blockEndPos = mb_strpos($string, '}');
            if (
                $blockStartPos
                && $blockEndPos
                && $blockStartPos < $symbolPosition
                && $blockEndPos > $symbolPosition
            ) {
                if ($replaceWith === 'SKIP_BLOCK') {
                    $length = $blockEndPos - $blockStartPos + 1;

                    return substr_replace($string, '', $blockStartPos, $length);
                } else {
                    $changedStr = substr_replace($string, "$replaceVal", $symbolPosition, 1);
                    $changedStr = substr_replace($changedStr, '', $blockStartPos, 1);

                    return substr_replace($changedStr, '', $blockEndPos - 1, 1);
                }
            }

            return substr_replace($string, "$replaceVal", $symbolPosition, 1);
        } catch (\Exception $exception) {
            throw new \Exception($exception->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = [], int $index = 0): string
    {
        try {
            $newQuery = $this->replaceFirstSpecifierAccuracy($this->query ?: $query, $args[$index] ?? NULL);
            if ($newQuery) {
                $this->query = $newQuery;

                return $this->buildQuery($this->query, $args, $index + 1);
            }

            $returnVal = $this->query ?: $query;
            $this->query = null;

            return $returnVal;
        } catch (\Exception $exception) {
            throw new \Exception($exception->getMessage());
        }
    }

    public function skip(): string
    {
        return 'SKIP_BLOCK';
    }
}
