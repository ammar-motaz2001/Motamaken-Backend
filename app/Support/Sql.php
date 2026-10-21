<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Small SQL pieces that differ between Postgres (production) and SQLite (tests).
 */
class Sql
{
    /**
     * Whether the connection is SQLite (the test suite), whose date functions differ from PostgreSQL.
     */
    private static function sqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    /** The hour of the day (0-23) of a timestamp column. */
    public static function hour(string $column): string
    {
        return self::sqlite() ? "cast(strftime('%H', {$column}) as integer)" : "extract(hour from {$column})::int";
    }

    /** Hours between two timestamp columns. */
    public static function hoursBetween(string $start, string $end): string
    {
        return self::sqlite() ? "(julianday({$end}) - julianday({$start})) * 24" : "extract(epoch from ({$end} - {$start})) / 3600";
    }

    /** The part of a MIME type before the slash (`image`, `application`...). */
    public static function mimeKind(string $column): string
    {
        return self::sqlite() ? "substr({$column}, 1, instr({$column}, '/') - 1)" : "split_part({$column}, '/', 1)";
    }
}
