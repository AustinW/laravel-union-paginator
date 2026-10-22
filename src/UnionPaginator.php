<?php

namespace AustinW\UnionPaginator;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator as BaseCursorPaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use UnexpectedValueException;
use stdClass;

/**
 * Paginates a UNION of several Eloquent models as one result set.
 */
class UnionPaginator
{
    use Conditionable;

    /**
     * Query builder method prefixes that may be called on the paginator. They apply to the whole union.
     */
    protected const FORWARDED_PREFIXES = ['where', 'orWhere', 'orderBy', 'latest', 'oldest', 'reorder', 'inRandomOrder'];

    /**
     * Columns appended to any ordering so that rows with equal sort values keep a stable order.
     */
    protected const TIE_BREAKERS = ['type', 'id'];

    /**
     * @var list<class-string<Model>>
     */
    protected array $modelTypes = [];

    /**
     * @var array<class-string<Model>, list<Closure>>
     */
    protected array $scopes = [];

    /**
     * @var array<class-string<Model>, Closure>
     */
    protected array $transformers = [];

    /**
     * @var array<class-string<Model>, array<mixed>>
     */
    protected array $selectedColumns = [];

    /**
     * @var array<class-string<Model>, Closure>
     */
    protected array $modelRetrievalCallbacks = [];

    /**
     * @var list<array{string, array<mixed>}>
     */
    protected array $forwardedCalls = [];

    protected bool $preventModelRetrieval = false;

    /**
     * @param class-string<Model>|list<class-string<Model>> $modelTypes
     */
    final public function __construct(array|string $modelTypes = [])
    {
        $this->setModelTypes(array_values(Arr::wrap($modelTypes)));
    }

    /**
     * @param list<class-string<Model>> $modelTypes
     */
    public static function forModels(array $modelTypes): static
    {
        return new static($modelTypes);
    }

    /**
     * @param class-string<Model> $modelType
     */
    public function addModelType(string $modelType): static
    {
        if (! is_subclass_of($modelType, Model::class)) {
            throw new InvalidArgumentException("$modelType is not a subclass of " . Model::class);
        }

        if (! in_array($modelType, $this->modelTypes, true)) {
            $this->modelTypes[] = $modelType;
        }

        return $this;
    }

    /**
     * @return list<class-string<Model>>
     */
    public function getModelTypes(): array
    {
        return $this->modelTypes;
    }

    /**
     * @param list<class-string<Model>> $modelTypes
     */
    public function setModelTypes(array $modelTypes): static
    {
        $this->modelTypes = [];

        foreach ($modelTypes as $modelType) {
            $this->addModelType($modelType);
        }

        return $this;
    }

    /**
     * Constrain one model's query before it joins the union.
     *
     * @param class-string<Model> $modelType
     * @param Closure(EloquentBuilder<Model>): mixed $callable
     */
    public function applyScope(string $modelType, Closure $callable): static
    {
        $this->assertRegistered($modelType);

        $this->scopes[$modelType][] = $callable;

        return $this;
    }

    /**
     * Columns to select for one model, in the same order for every model. Include an `id` alias; `type` is added last.
     *
     * @param class-string<Model> $modelType
     * @param array<mixed> $columns
     */
    public function setSelectedColumns(string $modelType, array $columns): static
    {
        $this->assertRegistered($modelType);

        $this->selectedColumns[$modelType] = $columns;

        return $this;
    }

    /**
     * Transform each result of one model type. Receives the model, or the raw row when model retrieval is prevented.
     *
     * @param class-string<Model> $modelType
     */
    public function transformResultsFor(string $modelType, Closure $callable): static
    {
        $this->assertRegistered($modelType);

        $this->transformers[$modelType] = $callable;

        return $this;
    }

    /**
     * Load the models of one type with a custom query. Receives the page's keys, returns a collection of models.
     *
     * @param class-string<Model> $modelType
     * @param Closure(array<int|string>): Collection<array-key, Model> $callback
     */
    public function fetchModelsUsing(string $modelType, Closure $callback): static
    {
        $this->assertRegistered($modelType);

        $this->modelRetrievalCallbacks[$modelType] = $callback;

        return $this;
    }

    /**
     * Return raw database rows instead of loading models.
     */
    public function preventModelRetrieval(): static
    {
        $this->preventModelRetrieval = true;

        return $this;
    }

    /**
     * @param array<string>|string $columns
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, mixed>
     */
    public function paginate(int $perPage = 15, array|string $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $paginator = $this->toBase()->paginate($perPage, $columns, $pageName, $page);

        return $paginator->setCollection($this->hydrate($paginator->getCollection()));
    }

    /**
     * Paginate without counting the total, which saves a COUNT over the whole union.
     *
     * @param array<string>|string $columns
     * @return \Illuminate\Pagination\AbstractPaginator<int, mixed>&Paginator<int, mixed>
     */
    public function simplePaginate(int $perPage = 15, array|string $columns = ['*'], string $pageName = 'page', ?int $page = null): Paginator
    {
        $paginator = $this->toBase()->simplePaginate($perPage, $columns, $pageName, $page);

        if (! $paginator instanceof AbstractPaginator) {
            throw new UnexpectedValueException('The bound Paginator must extend '.AbstractPaginator::class.'.');
        }

        return $paginator->setCollection($this->hydrate($paginator->getCollection()));
    }

    /**
     * Cursor paginate. The order must be unique and non-null: `type` and `id` are appended as tie-breakers.
     *
     * @param array<string>|string $columns
     * @return UnionCursorPaginator
     */
    public function cursorPaginate(int $perPage = 15, array|string $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        $query = $this->toBase();

        // Laravel builds cursors from column orders only; raw, random and expression orders would skip or repeat rows.
        foreach ($query->orders ?? [] as $order) {
            if (! is_string($order['column'] ?? null)) {
                throw new InvalidArgumentException('cursorPaginate() needs column orders. Raw, random and expression orders are not supported.');
            }
        }

        if (empty($query->orders)) {
            $this->addTieBreakers($query);
        }

        $paginator = $query->cursorPaginate($perPage, $columns, $cursorName, $cursor);

        if (! $paginator instanceof BaseCursorPaginator) {
            throw new UnexpectedValueException('The bound CursorPaginator must extend '.BaseCursorPaginator::class.'.');
        }

        return UnionCursorPaginator::fromRows($paginator, $this->hydrate($paginator->getCollection()));
    }

    /**
     * The query over the union, with the forwarded where and order calls applied.
     */
    public function toBase(): Builder
    {
        if (empty($this->modelTypes)) {
            throw new BadMethodCallException('No models have been added to the UnionPaginator.');
        }

        $models = array_map(fn (string $modelType) => new $modelType, $this->modelTypes);
        $connection = $models[0]->getConnection()->getName();
        $union = $this->queryFor($models[0]);

        foreach (array_slice($models, 1) as $model) {
            // The union runs as one statement, so every model must live on the same connection.
            if ($model->getConnection()->getName() !== $connection) {
                throw new InvalidArgumentException(sprintf(
                    '%s uses connection [%s], but the union runs on [%s].',
                    $model::class,
                    $model->getConnection()->getName(),
                    $connection
                ));
            }

            $union->union($this->queryFor($model));
        }

        $query = $union->getQuery()->newQuery()->fromSub($union, 'union_paginator');

        foreach ($this->forwardedCalls as [$method, $parameters]) {
            $query->{$method}(...$parameters);
        }

        if (! empty($query->orders)) {
            $this->addTieBreakers($query);
        }

        return $query;
    }

    /**
     * @param array<mixed> $parameters
     */
    public function __call(string $method, array $parameters): static
    {
        if (! Str::startsWith($method, self::FORWARDED_PREFIXES) || ! method_exists(Builder::class, $method)) {
            throw new BadMethodCallException(sprintf(
                'Call to undefined method %s::%s(). Only query builder where and order methods are forwarded to the union. Use applyScope() for model-specific constraints.',
                static::class,
                $method
            ));
        }

        $this->forwardedCalls[] = [$method, $parameters];

        return $this;
    }

    protected function addTieBreakers(Builder $query): void
    {
        $orders = $query->orders ?? [];
        $ordered = array_map(fn (array $order) => is_string($order['column'] ?? null) ? Str::afterLast($order['column'], '.') : null, $orders);
        $direction = (Arr::last($orders)['direction'] ?? null) === 'desc' ? 'desc' : 'asc';

        foreach (self::TIE_BREAKERS as $column) {
            if (! in_array($column, $ordered, true)) {
                $query->orderBy($column, $direction);
            }
        }
    }

    /**
     * @return EloquentBuilder<Model>
     */
    protected function queryFor(Model $model): EloquentBuilder
    {
        $query = $model->newQuery();

        $query->select($this->selectedColumns[$model::class] ?? $this->defaultColumns($model))
            ->selectRaw('? as type', [$model::class]);

        foreach ($this->scopes[$model::class] ?? [] as $scope) {
            $scope($query);
        }

        return $query;
    }

    /**
     * @return list<string|Expression>
     */
    protected function defaultColumns(Model $model): array
    {
        return [
            $model->getQualifiedKeyName() . ' as id',
            $this->timestampColumn($model, $model->getCreatedAtColumn(), 'created_at'),
            $this->timestampColumn($model, $model->getUpdatedAtColumn(), 'updated_at'),
        ];
    }

    /**
     * @param 'created_at'|'updated_at' $alias
     */
    protected function timestampColumn(Model $model, ?string $column, string $alias): string|Expression
    {
        if ($column) {
            return $model->qualifyColumn($column) . " as $alias";
        }

        // Postgres types an untyped NULL as text, which cannot be unioned with a timestamp column.
        return $model->getConnection()->getDriverName() === 'pgsql'
            ? DB::raw("null::timestamp as $alias")
            : DB::raw("null as $alias");
    }

    /**
     * Turn a page of union rows into models (or transformed rows).
     *
     * @param Collection<array-key, mixed> $rows
     * @return Collection<int, mixed>
     */
    protected function hydrate(Collection $rows): Collection
    {
        /** @var Collection<int, stdClass> $rows */
        $items = $this->preventModelRetrieval
            ? $rows->map(fn (stdClass $row) => $this->applyTransformer($row->type, clone $row))
            : $this->loadModels($rows);

        return $items->values();
    }

    /**
     * Load each page row's model with one query per model type. Rows without a model are skipped.
     *
     * @param Collection<int, stdClass> $rows
     * @return Collection<int, mixed>
     */
    protected function loadModels(Collection $rows): Collection
    {
        $models = [];

        foreach ($this->modelTypes as $modelType) {
            $ids = $rows->where('type', $modelType)->pluck('id')->unique()->values()->all();

            if (empty($ids)) {
                continue;
            }

            $loaded = isset($this->modelRetrievalCallbacks[$modelType])
                ? $this->modelRetrievalCallbacks[$modelType]($ids)
                : $modelType::findMany($ids);

            if (! is_iterable($loaded)) {
                throw new UnexpectedValueException("The fetchModelsUsing callback for $modelType must return a collection of models.");
            }

            foreach ($loaded as $model) {
                $models[$modelType][$model->getKey()] = $model;
            }
        }

        return $rows
            ->filter(fn (stdClass $row) => isset($models[$row->type][$row->id]))
            ->map(fn (stdClass $row) => $this->applyTransformer($row->type, $models[$row->type][$row->id]));
    }

    protected function applyTransformer(string $modelType, mixed $item): mixed
    {
        return isset($this->transformers[$modelType]) ? $this->transformers[$modelType]($item) : $item;
    }

    protected function assertRegistered(string $modelType): void
    {
        if (! in_array($modelType, $this->modelTypes, true)) {
            throw new InvalidArgumentException("Model type {$modelType} is not registered in this paginator.");
        }
    }
}
