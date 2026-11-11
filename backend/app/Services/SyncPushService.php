<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Center;
use App\Models\Room;
use App\Models\Student;
use App\Models\SyncMutation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SyncPushService
{
    private const DEFINITIONS = [
        'center' => [
            'class' => Center::class,
            'data' => ['name'],
        ],
        'student' => [
            'class' => Student::class,
            'data' => [
                'name',
                'student_phone',
                'parent_phone',
                'grade_level',
                'barcode',
                'center_uuid',
            ],
        ],
        'room' => [
            'class' => Room::class,
            'data' => ['name', 'capacity', 'center_uuid'],
        ],
        'attendance' => [
            'class' => Attendance::class,
            'data' => [
                'date',
                'status',
                'absence_reason',
                'recorded_by',
                'student_uuid',
            ],
        ],
    ];

    public function __construct(private ChangeLogService $changeLog)
    {
    }

    public function push(array $mutations): array
    {
        $results = [];

        foreach ($mutations as $mutation) {
            $results[] = $this->process($mutation);
        }

        return $results;
    }

    private function process(array $mutation): array
    {
        $mutationUuid = $mutation['mutation_uuid'] ?? null;
        $uuidValidator = Validator::make(
            ['mutation_uuid' => $mutationUuid],
            ['mutation_uuid' => ['required', 'uuid']],
        );

        if ($uuidValidator->fails()) {
            return $this->rejection(
                null,
                'validation_failed',
                ['mutation_uuid' => $uuidValidator->errors()->get('mutation_uuid')],
            );
        }

        $mutationUuid = strtolower((string) $mutationUuid);
        $mutation['mutation_uuid'] = $mutationUuid;
        $payloadHash = hash('sha256', $this->canonicalJson($mutation));

        try {
            return DB::transaction(function () use ($mutation, $mutationUuid, $payloadHash): array {
                $storedMutation = SyncMutation::query()
                    ->where('mutation_uuid', $mutationUuid)
                    ->lockForUpdate()
                    ->first();

                if ($storedMutation) {
                    return $this->existingResult($storedMutation, $payloadHash);
                }

                $storedMutation = SyncMutation::query()->create([
                    'mutation_uuid' => $mutationUuid,
                    'payload_hash' => $payloadHash,
                    'receipt' => ['status' => 'pending'],
                    'created_at' => now(),
                ]);

                $errors = $this->validationErrors($mutation);
                if ($errors !== []) {
                    return $this->storeReceipt($storedMutation, $this->rejection(
                        $mutationUuid,
                        'validation_failed',
                        $errors,
                        $mutation,
                    ));
                }

                $outcome = $this->applyMutation($mutation);

                return $this->storeReceipt($storedMutation, $outcome);
            });
        } catch (QueryException $exception) {
            $storedMutation = SyncMutation::query()
                ->where('mutation_uuid', $mutationUuid)
                ->first();

            if (! $storedMutation) {
                throw $exception;
            }

            return $this->existingResult($storedMutation, $payloadHash);
        }
    }

    private function existingResult(SyncMutation $storedMutation, string $payloadHash): array
    {
        if (! hash_equals($storedMutation->payload_hash, $payloadHash)) {
            return $this->rejection(
                $storedMutation->mutation_uuid,
                'mutation_uuid_reused',
                [],
            );
        }

        return $storedMutation->receipt;
    }

    private function storeReceipt(SyncMutation $storedMutation, array $receipt): array
    {
        $storedMutation->forceFill(['receipt' => $receipt])->save();

        return $receipt;
    }

    private function validationErrors(array $mutation): array
    {
        $allowedKeys = [
            'mutation_uuid',
            'entity_type',
            'operation',
            'entity_uuid',
            'base_version',
            'data',
        ];
        $errors = [];

        if (array_diff(array_keys($mutation), $allowedKeys) !== []) {
            $errors['mutation'][] = 'The mutation contains unsupported fields.';
        }

        $validator = Validator::make($mutation, [
            'mutation_uuid' => ['required', 'uuid'],
            'entity_type' => ['required', Rule::in(array_keys(self::DEFINITIONS))],
            'operation' => ['required', Rule::in(['create', 'update', 'delete'])],
            'entity_uuid' => ['required', 'uuid'],
            'base_version' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return array_merge_recursive($errors, $validator->errors()->toArray());
        }

        $operation = $mutation['operation'];
        $entityType = $mutation['entity_type'];
        $definition = self::DEFINITIONS[$entityType];
        $allowedData = $definition['data'];

        if ($operation === 'delete') {
            if (array_key_exists('data', $mutation)) {
                $data = $mutation['data'];
                if (! is_array($data) || $data !== []) {
                    $errors['data'][] = 'Delete mutations must not include entity data.';
                }
            }

            return $errors;
        }

        $dataValidator = Validator::make($mutation, [
            'data' => ['required', 'array', $operation === 'update' ? 'min:1' : 'min:1'],
        ]);
        if ($dataValidator->fails()) {
            $errors = array_merge_recursive($errors, $dataValidator->errors()->toArray());
        }

        $data = is_array($mutation['data'] ?? null) ? $mutation['data'] : [];
        if (array_diff(array_keys($data), $allowedData) !== []) {
            $errors['data'][] = 'The entity data contains unsupported fields.';
        }

        if ($operation === 'create' && (int) $mutation['base_version'] !== 0) {
            $errors['base_version'][] = 'Create mutations require base version 0.';
        }

        $rules = $this->dataRules($entityType, $operation);
        $dataValidation = Validator::make($data, $rules);
        if ($dataValidation->fails()) {
            $errors = array_merge_recursive(
                $errors,
                collect($dataValidation->errors()->toArray())
                    ->mapWithKeys(fn (array $messages, string $key): array => ["data.{$key}" => $messages])
                    ->all(),
            );
        }

        return $errors;
    }

    private function dataRules(string $entityType, string $operation): array
    {
        $required = $operation === 'create' ? 'required' : 'sometimes';

        return match ($entityType) {
            'center' => [
                'name' => [$required, 'string', 'max:255'],
            ],
            'student' => [
                'name' => [$required, 'string', 'max:255', 'regex:/^\S+(?:\s+\S+){1,2}$/u'],
                'student_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{11}$/'],
                'parent_phone' => [$operation === 'create' ? 'required' : 'sometimes', 'string', 'regex:/^[0-9]{11}$/'],
                'grade_level' => [$required, Rule::in(['prep_1', 'prep_2', 'prep_3'])],
                'barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
                'center_uuid' => [$operation === 'create' ? 'required' : 'sometimes', 'uuid'],
            ],
            'room' => [
                'name' => [$required, 'string', 'max:255'],
                'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
                'center_uuid' => [$operation === 'create' ? 'required' : 'sometimes', 'uuid'],
            ],
            'attendance' => [
                'date' => [$required, 'date'],
                'status' => [$required, Rule::in(['present', 'absent', 'late'])],
                'absence_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
                'recorded_by' => ['sometimes', 'nullable', 'string', 'max:255'],
                'student_uuid' => [$operation === 'create' ? 'required' : 'sometimes', 'uuid'],
            ],
        };
    }

    private function applyMutation(array $mutation): array
    {
        $entityType = $mutation['entity_type'];
        $operation = $mutation['operation'];
        $entityUuid = strtolower($mutation['entity_uuid']);
        $entityClass = self::DEFINITIONS[$entityType]['class'];
        $entity = $entityClass::query()
            ->where('uuid', $entityUuid)
            ->lockForUpdate()
            ->first();

        if ($operation === 'create') {
            if ((int) $mutation['base_version'] !== 0) {
                return $this->rejection($mutation['mutation_uuid'], 'invalid_base_version', [], $mutation);
            }
            if ($entity) {
                return $this->rejection($mutation['mutation_uuid'], 'entity_already_exists', [], $mutation);
            }

            $attributes = $this->resolveAttributes($entityType, $mutation['data']);
            if (isset($attributes['error'])) {
                return $this->rejection(
                    $mutation['mutation_uuid'],
                    'relationship_not_found',
                    [$attributes['error_field'] => [$attributes['error']]],
                    $mutation,
                );
            }
            $uniqueErrors = $this->uniqueErrors($entityType, $attributes);
            if ($uniqueErrors !== []) {
                return $this->rejection(
                    $mutation['mutation_uuid'],
                    'unique_constraint',
                    $uniqueErrors,
                    $mutation,
                );
            }

            $entity = $this->changeLog->create($entityType, $attributes, $entityUuid);

            return $this->appliedReceipt($mutation, $entity);
        }

        if (! $entity) {
            return $this->rejection($mutation['mutation_uuid'], 'entity_not_found', [], $mutation);
        }

        $baseVersion = (int) $mutation['base_version'];
        $currentVersion = (int) $entity->getAttribute('version');
        if ($baseVersion !== $currentVersion) {
            return $this->rejection($mutation['mutation_uuid'], 'stale_version', [], $mutation, [
                'base_version' => $baseVersion,
                'current_version' => $currentVersion,
            ]);
        }

        if ($operation === 'delete') {
            $snapshot = $this->changeLog->snapshotFor($entityType, $entity);
            $this->changeLog->delete($entity);

            return [
                'mutation_uuid' => $mutation['mutation_uuid'],
                'status' => 'applied',
                'entity_type' => $entityType,
                'operation' => 'delete',
                'entity_uuid' => $entityUuid,
                'entity_version' => $currentVersion,
                'entity' => $snapshot,
            ];
        }

        $attributes = $this->resolveAttributes($entityType, $mutation['data']);
        if (isset($attributes['error'])) {
            return $this->rejection(
                $mutation['mutation_uuid'],
                'relationship_not_found',
                [$attributes['error_field'] => [$attributes['error']]],
                $mutation,
            );
        }
        $uniqueErrors = $this->uniqueErrors($entityType, $attributes, $entity);
        if ($uniqueErrors !== []) {
            return $this->rejection(
                $mutation['mutation_uuid'],
                'unique_constraint',
                $uniqueErrors,
                $mutation,
            );
        }

        $updated = $this->changeLog->update($entity, $attributes);

        return $this->appliedReceipt($mutation, $updated);
    }

    private function resolveAttributes(string $entityType, array $data): array
    {
        if ($entityType === 'student' || $entityType === 'room') {
            if (array_key_exists('center_uuid', $data)) {
                $center = Center::query()->where('uuid', strtolower($data['center_uuid']))->first();
                if (! $center) {
                    return ['error_field' => 'data.center_uuid', 'error' => 'The selected center UUID does not exist.'];
                }

                $data['center_id'] = $center->getKey();
                unset($data['center_uuid']);
            }
        } elseif ($entityType === 'attendance' && array_key_exists('student_uuid', $data)) {
            $student = Student::query()->where('uuid', strtolower($data['student_uuid']))->first();
            if (! $student) {
                return ['error_field' => 'data.student_uuid', 'error' => 'The selected student UUID does not exist.'];
            }

            $data['student_id'] = $student->getKey();
            unset($data['student_uuid']);
        }

        return $data;
    }

    private function appliedReceipt(array $mutation, Model $entity): array
    {
        $snapshot = $this->changeLog->snapshotFor($mutation['entity_type'], $entity);

        return [
            'mutation_uuid' => $mutation['mutation_uuid'],
            'status' => 'applied',
            'entity_type' => $mutation['entity_type'],
            'operation' => $mutation['operation'],
            'entity_uuid' => $entity->getAttribute('uuid'),
            'entity_version' => (int) $entity->getAttribute('version'),
            'no_op' => $mutation['operation'] === 'update'
                && (int) $entity->getAttribute('version') === (int) $mutation['base_version'],
            'entity' => $snapshot,
        ];
    }

    private function uniqueErrors(string $entityType, array $attributes, ?Model $entity = null): array
    {
        $errors = [];
        $excludeId = $entity?->getKey();

        if ($entityType === 'center' && isset($attributes['name'])) {
            $query = Center::query()->where('name', $attributes['name']);
            if ($excludeId !== null) {
                $query->whereKeyNot($excludeId);
            }
            if ($query->exists()) {
                $errors['data.name'][] = 'The center name is already in use.';
            }
        } elseif ($entityType === 'student') {
            foreach (['student_phone', 'barcode'] as $field) {
                if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
                    continue;
                }

                $query = Student::query()->where($field, $attributes[$field]);
                if ($excludeId !== null) {
                    $query->whereKeyNot($excludeId);
                }
                if ($query->exists()) {
                    $errors["data.{$field}"][] = "The {$field} is already in use.";
                }
            }
        } elseif ($entityType === 'room') {
            $centerId = $attributes['center_id'] ?? $entity?->getAttribute('center_id');
            $name = $attributes['name'] ?? $entity?->getAttribute('name');
            if ($centerId !== null && $name !== null) {
                $query = Room::query()->where('center_id', $centerId)->where('name', $name);
                if ($excludeId !== null) {
                    $query->whereKeyNot($excludeId);
                }
                if ($query->exists()) {
                    $errors['data.name'][] = 'The room name is already in use at this center.';
                }
            }
        } elseif ($entityType === 'attendance') {
            $studentId = $attributes['student_id'] ?? $entity?->getAttribute('student_id');
            $date = $attributes['date'] ?? $entity?->getAttribute('date');
            if ($studentId !== null && $date !== null) {
                $query = Attendance::query()
                    ->where('student_id', $studentId)
                    ->whereDate('date', $date);
                if ($excludeId !== null) {
                    $query->whereKeyNot($excludeId);
                }
                if ($query->exists()) {
                    $errors['data.date'][] = 'Attendance already exists for this student and date.';
                }
            }
        }

        return $errors;
    }

    private function rejection(
        ?string $mutationUuid,
        string $code,
        array $errors,
        array $mutation = [],
        array $extra = [],
    ): array {
        return array_merge([
            'mutation_uuid' => $mutationUuid,
            'status' => 'rejected',
            'code' => $code,
            'entity_type' => isset($mutation['entity_type'])
                && is_string($mutation['entity_type'])
                && array_key_exists($mutation['entity_type'], self::DEFINITIONS)
                    ? $mutation['entity_type']
                    : null,
            'operation' => isset($mutation['operation'])
                && in_array($mutation['operation'], ['create', 'update', 'delete'], true)
                    ? $mutation['operation']
                    : null,
            'entity_uuid' => isset($mutation['entity_uuid'])
                && is_string($mutation['entity_uuid'])
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $mutation['entity_uuid'])
                    ? strtolower($mutation['entity_uuid'])
                    : null,
            'errors' => (object) $errors,
        ], $extra);
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (array $data) use (&$sort): array {
            foreach ($data as $key => $item) {
                if (is_array($item)) {
                    $data[$key] = $sort($item);
                }
            }

            if (! array_is_list($data)) {
                ksort($data);
            }

            return $data;
        };

        return json_encode(
            $sort($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
