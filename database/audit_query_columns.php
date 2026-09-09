<?php

declare(strict_types=1);

/**
 * Find query-column references that are absent from the initialization schema.
 *
 * This is intentionally conservative static analysis. Its output is a review
 * queue, not SQL that should be applied without checking the source context.
 */

$root = dirname(__DIR__);
$modelDir = $root . '/app/Model';
$sourceDir = $root . '/app';
$schemaFile = $root . '/database/init.sql';

$classes = [];
$modelFiles = [];
foreach (glob($modelDir . '/*.php') as $file) {
    $source = file_get_contents($file);
    if (!preg_match('/class\s+([A-Za-z0-9_]+)\s+extends\s+Model/', $source, $classMatch)) {
        continue;
    }
    if (!preg_match('/protected\s+\$table\s*=\s*[\'\"]([a-z0-9_]+)[\'\"]/', $source, $tableMatch)) {
        continue;
    }
    $classes[$classMatch[1]] = $tableMatch[1];
    $modelFiles[$file] = $tableMatch[1];
}

$knownColumns = [];
$sql = file_get_contents($schemaFile);
if ($sql === false) {
    throw new RuntimeException('Initialization schema not found: ' . $schemaFile);
}
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? `([^`]+)` \((.*?)\n\) ENGINE=/s', $sql, $tableBlocks, PREG_SET_ORDER);
foreach ($tableBlocks as $block) {
    preg_match_all('/^\s+`([^`]+)`\s+/m', $block[2], $columnMatches);
    $knownColumns[$block[1]] = array_fill_keys($columnMatches[1], true);
}

$physicalTable = static fn (string $table): string => $table === 'deployment_webhook' ? $table : 'galaxy_' . $table;
$findings = [];

$record = static function (string $table, string $rawColumn, string $file, int $offset) use (&$findings, $knownColumns, $physicalTable): void {
    $rawColumn = preg_replace('/\s+as\s+.+$/i', '', trim($rawColumn));
    if ($rawColumn === '*' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $rawColumn)) {
        return;
    }
    if (str_contains($rawColumn, '.')) {
        [$qualifier, $column] = explode('.', $rawColumn, 2);
        $table = str_starts_with($qualifier, 'galaxy_') ? substr($qualifier, 7) : $qualifier;
    } else {
        $column = $rawColumn;
    }
    $physical = $physicalTable($table);
    if (!isset($knownColumns[$physical]) || isset($knownColumns[$physical][$column])) {
        return;
    }
    $line = substr_count(substr(file_get_contents($file), 0, $offset), "\n") + 1;
    $findings[$physical . '.' . $column . '|' . $file . ':' . $line] = true;
};

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir));
foreach ($files as $fileInfo) {
    if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
        continue;
    }
    $file = $fileInfo->getPathname();
    $source = file_get_contents($file);

    $targets = [];
    foreach ($classes as $class => $table) {
        if (preg_match('/\b' . preg_quote($class, '/') . '::/', $source)) {
            $targets[$class] = $table;
        }
    }
    foreach ($targets as $class => $table) {
        $chunks = [];
        preg_match_all('/\b' . preg_quote($class, '/') . '::.{0,6000}?;/s', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$chunk, $offset]) {
            $chunks[] = [$chunk, $offset];
        }

        if (($modelFiles[$file] ?? null) === $table) {
            preg_match_all('/(?:\$this->|\bself::).{0,6000}?;/s', $source, $instanceMatches, PREG_OFFSET_CAPTURE);
            foreach ($instanceMatches[0] as [$chunk, $offset]) {
                $chunks[] = [$chunk, $offset];
            }
        }

        foreach ($chunks as [$chunk, $chunkOffset]) {
            preg_match_all('/->(?:where|orWhere|whereIn|orWhereIn|whereNotIn|whereBetween|orderBy|orderByDesc|groupBy|value|pluck|increment|decrement)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $chunk, $singleFields, PREG_OFFSET_CAPTURE);
            foreach ($singleFields[1] as [$field, $offset]) {
                $record($table, $field, $file, $chunkOffset + $offset);
            }

            preg_match_all('/(?:::|->)(?:select|addSelect|get)\s*\(([^;]{0,2000}?)\)/s', $chunk, $selectCalls, PREG_OFFSET_CAPTURE);
            foreach ($selectCalls[1] as [$arguments, $offset]) {
                preg_match_all('/[\'\"]([a-zA-Z_][a-zA-Z0-9_.]*(?:\s+as\s+[a-zA-Z_][a-zA-Z0-9_]*)?)[\'\"]/', $arguments, $fields);
                foreach ($fields[1] as $field) {
                    $record($table, $field, $file, $chunkOffset + $offset);
                }
            }

            preg_match_all('/(?:::|->)(?:create|insert|insertGetId|update|updateOrCreate|firstOrCreate|where)\s*\(\s*\[([^;]{0,3000}?)\]/s', $chunk, $arrayCalls, PREG_OFFSET_CAPTURE);
            foreach ($arrayCalls[1] as [$arguments, $offset]) {
                preg_match_all('/[\'\"]([a-zA-Z_][a-zA-Z0-9_]*)[\'\"]\s*=>/', $arguments, $fields);
                foreach ($fields[1] as $field) {
                    $record($table, $field, $file, $chunkOffset + $offset);
                }
            }
        }
    }
}

ksort($findings);
foreach (array_keys($findings) as $finding) {
    echo $finding, "\n";
}
