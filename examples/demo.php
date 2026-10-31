<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

/** Actual Laravel migrations against ephemeral SQLite. No external services. */
function resetDatabase(): void
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getContainer()->instance('db', $capsule->getDatabaseManager());
    $capsule->getContainer()->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($capsule->getContainer());
    Schema::create('payments', function (Blueprint $table): void {
        $table->id();
        $table->string('status');
    });
    Capsule::table('payments')->insert(['status' => 'pending']);
}

resetDatabase();
(require __DIR__.'/unsafe.php')->up();
try {
    Capsule::table('payments')->insert(['status' => 'paid']);
    throw new RuntimeException('Expected the old writer to fail');
} catch (Illuminate\Database\QueryException $e) {
    echo "BEFORE: immediate rename breaks old writer (status column missing).\n";
}

resetDatabase();
(require __DIR__.'/staged/01_expand.php')->up();
Capsule::table('payments')->insert(['status' => 'paid']); // old instance
Capsule::table('payments')->insert(['status' => 'paid', 'state' => 'paid']); // new dual writer
// Tiny demonstration only: production backfills need bounded batches and race handling.
Capsule::table('payments')->whereNull('state')->update(['state' => Capsule::raw('status')]);
if (Capsule::table('payments')->whereNull('state')->exists()) {
    throw new RuntimeException('Backfill incomplete');
}
echo "AFTER expand: old and new writers both succeed; backfill validated.\n";
// Operational gate: all old readers/writers/jobs are gone, rollback window closed,
// and new writers now use state only. The analyzer cannot verify this gate.
(require __DIR__.'/staged/03_contract.php')->up();
Capsule::table('payments')->insert(['state' => 'refunded']);
echo "AFTER contract: new-only writer succeeds after old instances are drained.\n";
