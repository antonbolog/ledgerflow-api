<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Support\Facades\DB;

/**
 * Read from the observer connection and filtered to the current database, because the development database shares this server.
 * Booleans are cast to int in SQL: PDO may return 'f', which is truthy in PHP.
 */
final class ProjectionGateLocks
{
    /**
     * @return list<array{pid: int, mode: string, granted: bool, classid: int, objid: int, objsubid: int}>
     */
    public static function onKey(int $classId, int $objectId): array
    {
        $rows = DB::connection(IntegrationDatabase::OBSERVER)->select(
            <<<'SQL'
            select pid::int          as pid,
                   mode,
                   granted::int      as granted,
                   classid::int      as classid,
                   objid::int        as objid,
                   objsubid::int     as objsubid
              from pg_locks
             where locktype = 'advisory'
               and database = (select oid from pg_database where datname = current_database())
               and classid = ?
               and objid = ?
             order by granted desc, pid
            SQL,
            [$classId, $objectId],
        );

        return array_map(static fn (object $row): array => [
            'pid' => (int) $row->pid,
            'mode' => (string) $row->mode,
            'granted' => ((int) $row->granted) === 1,
            'classid' => (int) $row->classid,
            'objid' => (int) $row->objid,
            'objsubid' => (int) $row->objsubid,
        ], $rows);
    }

    public static function pid(string $connection): int
    {
        $row = DB::connection($connection)->selectOne('select pg_backend_pid() as pid');

        return (int) $row->pid;
    }

    public static function releaseAll(string $connection): void
    {
        DB::connection($connection)->select('select pg_advisory_unlock_all()');
    }
}
