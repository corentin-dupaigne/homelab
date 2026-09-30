<?php

// Copies every row of a Laravel app's MySQL database into its PostgreSQL
// database. Run it inside the app's image with `php artisan tinker <file>`:
//
// - The default connection must be the PostgreSQL target, already migrated
//   (`php artisan migrate --force`), so the schema is Laravel's own.
// - SOURCE_DB_HOST, SOURCE_DB_PORT, SOURCE_DB_DATABASE, SOURCE_DB_USERNAME and
//   SOURCE_DB_PASSWORD point at the MySQL source.
//
// Everything in the target, seeded rows and the migrations table included, is
// replaced by the source's rows in one transaction, so a failure leaves the
// target as it was and the script can simply be run again.

use Illuminate\Support\Facades\DB;

config(['database.connections.source' => array_merge(config('database.connections.mysql'), [
    'host' => getenv('SOURCE_DB_HOST'),
    'port' => getenv('SOURCE_DB_PORT') ?: 3306,
    'database' => getenv('SOURCE_DB_DATABASE'),
    'username' => getenv('SOURCE_DB_USERNAME'),
    'password' => getenv('SOURCE_DB_PASSWORD'),
])]);
$source = DB::connection('source');
$target = DB::connection();

$tables = collect($target->select(
    'select tablename from pg_tables where schemaname = current_schema()'
))->pluck('tablename')->all();

// Insert parents before children: nothing here may disable foreign-key checks,
// since that needs a PostgreSQL superuser.
$parents = array_fill_keys($tables, []);
foreach ($target->select(
    "select c.conrelid::regclass::text as child, c.confrelid::regclass::text as parent
       from pg_constraint c where c.contype = 'f' and c.conrelid <> c.confrelid"
) as $fk) {
    $parents[$fk->child][] = $fk->parent;
}
$ordered = [];
while ($parents) {
    $ready = array_keys(array_filter($parents, fn ($p) => ! array_diff($p, $ordered)));
    if (! $ready) {
        throw new RuntimeException('Foreign-key cycle between: '.implode(', ', array_keys($parents)));
    }
    array_push($ordered, ...$ready);
    $parents = array_diff_key($parents, array_flip($ready));
}

$target->transaction(function () use ($source, $target, $ordered) {
    $target->statement('truncate '.implode(', ', array_map(fn ($t) => '"'.$t.'"', $ordered)).' cascade');

    foreach ($ordered as $table) {
        // MySQL has no boolean type: tinyint(1) arrives as 0/1, which PostgreSQL
        // will not implicitly cast into a boolean column.
        $booleans = collect($target->select(
            "select column_name from information_schema.columns
              where table_schema = current_schema() and table_name = ? and data_type = 'boolean'",
            [$table]
        ))->pluck('column_name')->all();

        $batch = [];
        $flush = function () use (&$batch, $target, $table) {
            if ($batch) {
                $target->table($table)->insert($batch);
                $batch = [];
            }
        };
        foreach ($source->table($table)->cursor() as $row) {
            $row = (array) $row;
            foreach ($booleans as $column) {
                if ($row[$column] !== null) {
                    $row[$column] = (bool) $row[$column];
                }
            }
            $batch[] = $row;
            if (count($batch) === 500) {
                $flush();
            }
        }
        $flush();

        // Explicit ids leave sequences behind; the next insert would collide.
        foreach ($target->select(
            "select column_name, pg_get_serial_sequence(quote_ident(table_name), column_name) as seq
               from information_schema.columns
              where table_schema = current_schema() and table_name = ?
                and pg_get_serial_sequence(quote_ident(table_name), column_name) is not null",
            [$table]
        ) as $serial) {
            $target->select(
                "select setval(?, coalesce((select max(\"{$serial->column_name}\") from \"{$table}\"), 0) + 1, false)",
                [$serial->seq]
            );
        }

        $from = $source->table($table)->count();
        $to = $target->table($table)->count();
        if ($from !== $to) {
            throw new RuntimeException("{$table}: {$from} rows in MySQL, {$to} copied");
        }
        echo str_pad($table, 30)."{$to} rows\n";
    }
});

echo "Done.\n";
