<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Center;
use App\Models\Room;
use App\Models\Student;
use App\Models\SyncChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ChangeLogService
{
    private const DEFINITIONS = [
        'center' => [
            'class' => Center::class,
            'attributes' => ['name'],
            'snapshot' => ['id', 'uuid', 'version', 'name', 'created_at', 'updated_at'],
        ],
        'student' => [
            'class' => Student::class,
            'attributes' => [
                'name',
                'student_phone',
                'parent_phone',
                'grade_level',
                'center_id',
                'barcode',
            ],
            'snapshot' => [
                'id',
                'uuid',
                'version',
                'name',
                'student_phone',
                'parent_phone',
                'grade_level',
                'center_id',
                'barcode',
                'created_at',
                'updated_at',
            ],
        ],
        'room' => [
            'class' => Room::class,
            'attributes' => ['center_id', 'name', 'capacity'],
            'snapshot' => [
                'id',
                'uuid',
                'version',
                'center_id',
                'name',
                'capacity',
                'created_at',
                'updated_at',
            ],
        ],
        'attendance' => [
            'class' => Attendance::class,
            'attributes' => [
                'student_id',
                'date',
                'status',
                'absence_reason',
                'recorded_by',
            ],
            'snapshot' => [
                'id',
                'uuid',
                'version',
                'student_id',
                'date',
                'status',
                'absence_reason',
                'recorded_by',
                'created_at',
                'updated_at',
            ],
        ],
    ];

    public function create(string $entityType, array $attributes, ?string $uuid = null): Model
    {
        $definition = $this->definitionForType($entityType);

        return DB::transaction(function () use ($entityType, $definition, $attributes, $uuid): Model {
            $class = $definition['class'];
            $entity = new $class();
            $entity->fill($this->onlyAllowedAttributes($entityType, $attributes));
            if ($uuid !== null) {
                $entity->setAttribute('uuid', $uuid);
            }

            if (! $entity->save()) {
                throw new RuntimeException("Unable to create {$entityType}.");
            }

            $this->append($entityType, 'created', $entity);

            return $entity;
        });
    }

    public function update(Model $entity, array $attributes): Model
    {
        $entityType = $this->typeForModel($entity);

        return DB::transaction(function () use ($entity, $entityType, $attributes): Model {
            $lockedEntity = $entity->newQuery()
                ->whereKey($entity->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $originalUuid = $lockedEntity->getAttribute('uuid');
            $lockedEntity->fill($this->onlyAllowedAttributes($entityType, $attributes));

            $changed = collect($this->definitionForType($entityType)['attributes'])
                ->contains(fn (string $attribute): bool => $lockedEntity->isDirty($attribute));

            if (! $changed) {
                return $lockedEntity;
            }

            $lockedEntity->setAttribute('uuid', $originalUuid);
            $lockedEntity->setAttribute('version', (int) $lockedEntity->getAttribute('version') + 1);

            if (! $lockedEntity->save()) {
                throw new RuntimeException("Unable to update {$entityType}.");
            }

            $this->append($entityType, 'updated', $lockedEntity);

            return $lockedEntity;
        });
    }

    public function delete(Model $entity): void
    {
        $entityType = $this->typeForModel($entity);

        DB::transaction(function () use ($entity, $entityType): void {
            $lockedEntity = $entity->newQuery()
                ->whereKey($entity->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $descendants = $this->descendantsFor($lockedEntity);

            foreach ($descendants as [$descendantType, $descendant]) {
                $this->append($descendantType, 'deleted', $descendant);
            }

            $snapshot = $this->snapshot($entityType, $lockedEntity);

            if (! $lockedEntity->delete()) {
                throw new RuntimeException("Unable to delete {$entityType}.");
            }

            $this->appendSnapshot($entityType, 'deleted', $snapshot);
        });
    }

    public function upsertAttendance(array $attributes): Attendance
    {
        return DB::transaction(function () use ($attributes): Attendance {
            $key = [
                'student_id' => $attributes['student_id'],
                'date' => $attributes['date'],
            ];
            $attendance = Attendance::query()
                ->where('student_id', $key['student_id'])
                ->whereDate('date', $key['date'])
                ->lockForUpdate()
                ->first();

            if (! $attendance) {
                return $this->create('attendance', $attributes);
            }

            return $this->update($attendance, [
                'status' => $attributes['status'],
                'absence_reason' => $attributes['absence_reason'] ?? null,
                'recorded_by' => $attributes['recorded_by'] ?? null,
            ]);
        });
    }

    public function snapshotFor(string $entityType, Model $entity): array
    {
        return $this->snapshot($entityType, $entity);
    }

    private function descendantsFor(Model $entity): array
    {
        if ($entity instanceof Center) {
            $students = Student::query()->where('center_id', $entity->getKey())->orderBy('id')->get();
            $rooms = Room::query()->where('center_id', $entity->getKey())->orderBy('id')->get();
            $attendances = Attendance::query()
                ->whereIn('student_id', $students->modelKeys())
                ->orderBy('id')
                ->get();

            return [
                ...$this->typedModels('attendance', $attendances),
                ...$this->typedModels('student', $students),
                ...$this->typedModels('room', $rooms),
            ];
        }

        if ($entity instanceof Student) {
            $attendances = Attendance::query()
                ->where('student_id', $entity->getKey())
                ->orderBy('id')
                ->get();

            return $this->typedModels('attendance', $attendances);
        }

        return [];
    }

    private function typedModels(string $entityType, iterable $entities): array
    {
        $typed = [];

        foreach ($entities as $entity) {
            $typed[] = [$entityType, $entity];
        }

        return $typed;
    }

    private function append(string $entityType, string $operation, Model $entity): void
    {
        $this->appendSnapshot($entityType, $operation, $this->snapshot($entityType, $entity));
    }

    private function appendSnapshot(string $entityType, string $operation, array $snapshot): void
    {
        if (! in_array($operation, ['created', 'updated', 'deleted'], true)) {
            throw new InvalidArgumentException("Unsupported change operation: {$operation}.");
        }

        if (empty($snapshot['uuid'])) {
            throw new RuntimeException("Cannot log {$entityType} without a UUID.");
        }

        SyncChange::query()->create([
            'entity_type' => $entityType,
            'entity_uuid' => $snapshot['uuid'],
            'operation' => $operation,
            'entity_version' => (int) $snapshot['version'],
            'payload' => $snapshot,
            'created_at' => now(),
        ]);
    }

    private function snapshot(string $entityType, Model $entity): array
    {
        $snapshot = collect($this->definitionForType($entityType)['snapshot'])
            ->mapWithKeys(fn (string $attribute): array => [
                $attribute => $entity->getAttribute($attribute),
            ])
            ->all();

        if ($entity instanceof Student) {
            $snapshot['center_uuid'] = $entity->center()->value('uuid');
        } elseif ($entity instanceof Room) {
            $snapshot['center_uuid'] = $entity->center()->value('uuid');
        } elseif ($entity instanceof Attendance) {
            $snapshot['student_uuid'] = $entity->student()->value('uuid');
        }

        return $snapshot;
    }

    private function onlyAllowedAttributes(string $entityType, array $attributes): array
    {
        return array_intersect_key(
            $attributes,
            array_flip($this->definitionForType($entityType)['attributes']),
        );
    }

    private function definitionForType(string $entityType): array
    {
        if (! isset(self::DEFINITIONS[$entityType])) {
            throw new InvalidArgumentException("Unsupported entity type: {$entityType}.");
        }

        return self::DEFINITIONS[$entityType];
    }

    private function typeForModel(Model $entity): string
    {
        foreach (self::DEFINITIONS as $entityType => $definition) {
            if ($entity instanceof $definition['class']) {
                return $entityType;
            }
        }

        throw new InvalidArgumentException('Unsupported entity model.');
    }
}
