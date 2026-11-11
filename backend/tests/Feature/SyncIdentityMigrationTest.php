<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncIdentityMigrationTest extends TestCase
{
    private string $originalDefaultConnection;
    private string $connectionName = 'sync_identity_migration_test';

    public function test_migration_backfills_legacy_rows_and_preserves_constraints(): void
    {
        $this->useDisposableConnection();
        $this->createLegacySchema();
        $this->insertLegacyRecords();

        $migration = require database_path('migrations/2026_10_31_000001_add_sync_identity_to_entities.php');
        $migration->up();

        $connection = DB::connection();
        $uuids = [];

        foreach (['centers', 'students', 'rooms', 'attendances'] as $table) {
            $records = DB::table($table)->orderBy('id')->get();

            $this->assertCount(1, $records);
            $record = $records->first();
            $uuids[] = $record->uuid;

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $record->uuid,
            );
            $this->assertSame(1, (int) $record->version);
        }

        $this->assertCount(count($uuids), array_unique($uuids));
        $this->assertSame(41, DB::table('centers')->value('id'));
        $this->assertSame(52, DB::table('students')->value('id'));
        $this->assertSame(63, DB::table('rooms')->value('id'));
        $this->assertSame(74, DB::table('attendances')->value('id'));

        $this->assertSame('CASCADE', $this->foreignKeyDeleteAction('students', 'center_id'));
        $this->assertSame('CASCADE', $this->foreignKeyDeleteAction('rooms', 'center_id'));
        $this->assertSame('CASCADE', $this->foreignKeyDeleteAction('attendances', 'student_id'));
        $this->assertTrue($this->hasIndex('students', 'students_barcode_unique'));
        $this->assertTrue($this->hasIndex('rooms', 'rooms_center_id_name_unique'));
        $this->assertTrue($this->hasIndex('attendances', 'attendances_student_id_date_unique'));

        foreach (['centers', 'students', 'rooms', 'attendances'] as $table) {
            $this->assertTrue($this->hasIndex($table, "{$table}_uuid_unique"));
        }

        $this->assertSame(1, (int) $connection->scalar('PRAGMA foreign_keys'));

        DB::table('centers')->where('id', 41)->delete();

        $this->assertDatabaseCount('centers', 0);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('attendances', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));

        $this->originalDefaultConnection = config('database.default');
    }

    protected function tearDown(): void
    {
        DB::purge($this->connectionName);
        config(['database.default' => $this->originalDefaultConnection]);
        parent::tearDown();
    }

    private function useDisposableConnection(): void
    {
        config([
            "database.connections.{$this->connectionName}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => null,
                'journal_mode' => null,
                'synchronous' => null,
            ],
            'database.default' => $this->connectionName,
        ]);

        DB::purge($this->connectionName);
    }

    private function createLegacySchema(): void
    {
        Schema::create('centers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('student_phone')->nullable();
            $table->string('parent_phone');
            $table->string('grade_level');
            $table->foreignId('center_id')->constrained('centers')->cascadeOnDelete();
            $table->timestamps();
            $table->string('barcode')->nullable()->unique();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->timestamps();
            $table->unique(['center_id', 'name']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('date');
            $table->string('status')->default('present');
            $table->string('absence_reason')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'date']);
        });
    }

    private function insertLegacyRecords(): void
    {
        DB::table('centers')->insert([
            'id' => 41,
            'name' => 'Legacy Center',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('students')->insert([
            'id' => 52,
            'name' => 'Legacy Student',
            'parent_phone' => '01012345678',
            'grade_level' => 'prep_1',
            'center_id' => 41,
            'barcode' => 'LEGACY-52',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rooms')->insert([
            'id' => 63,
            'center_id' => 41,
            'name' => 'Legacy Room',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attendances')->insert([
            'id' => 74,
            'student_id' => 52,
            'date' => '2026-10-30',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function foreignKeyDeleteAction(string $table, string $column): ?string
    {
        $foreignKeys = DB::select("PRAGMA foreign_key_list({$table})");

        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->from === $column) {
                return $foreignKey->on_delete;
            }
        }

        return null;
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (DB::select("PRAGMA index_list({$table})") as $index) {
            if ($index->name === $indexName) {
                return true;
            }
        }

        return false;
    }
}
