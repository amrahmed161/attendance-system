<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $tables = [
        'centers',
        'students',
        'rooms',
        'attendances',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('uuid')->nullable();
                $table->unsignedInteger('version')->default(1);
            });

            DB::table($tableName)
                ->orderBy('id')
                ->chunkById(500, function ($records) use ($tableName) {
                    foreach ($records as $record) {
                        $values = ['version' => 1];

                        if ($record->uuid === null) {
                            $values['uuid'] = (string) Str::uuid();
                        }

                        DB::table($tableName)
                            ->where('id', $record->id)
                            ->update($values);
                    }
                });

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->unique('uuid', "{$tableName}_uuid_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropUnique("{$tableName}_uuid_unique");
                $table->dropColumn(['uuid', 'version']);
            });
        }
    }
};
