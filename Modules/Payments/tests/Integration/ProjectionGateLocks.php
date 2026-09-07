<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL's own record of who holds the gate, read from a third party.
 *
 * ALWAYS FROM THE OBSERVER CONNECTION, hard coded, for the rule T4's spike
 * established: an observation of "who holds this lock" made by the holder is not
 * an observation. Every affinity assertion in the gate suite compares a pid the
 * unit under test reported against a pid PostgreSQL reported to somebody else.
 *
 * FILTERED BY THE CURRENT DATABASE OID. Advisory locks are per-database but
 * pg_locks reports the whole cluster, and the development database ledgerflow
 * shares this PostgreSQL instance, so an unfiltered read would count a
 * developer's psql session holding the same key and silently change a contention
 * result.
 *
 * Booleans and OIDs are cast to int in SQL rather than read raw: PDO's pgsql
 * driver can hand a boolean back as 't'/'f', and 'f' is truthy in PHP.
 */
final class ProjectionGateLocks
{
    /**
     * Every advisory lock on one key, in this database, ordered for determinism.
     *
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

    /** The backend pid behind a named connection, as PostgreSQL reports it. */
    public static function pid(string $connection): int
    {
        $row = DB::connection($connection)->selectOne('select pg_backend_pid() as pid');

        return (int) $row->pid;
    }

    /**
     * Release every session-level advisory lock a connection still holds.
     *
     * Transaction-scoped locks die with their transaction; this exists for the
     * session-level ones a rebuild-shaped test takes, and for leaving the key
     * free for the next test's guard.
     */
    public static function releaseAll(string $connection): void
    {
        DB::connection($connection)->select('select pg_advisory_unlock_all()');
    }
}
