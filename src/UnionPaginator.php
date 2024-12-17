<?php

namespace AustinW\UnionPaginator;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Traits\ForwardsCalls;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

/**
 * A paginator that supports union queries across multiple model types.
 */
class UnionPaginator
{
    use ForwardsCalls;

    /**
     * @var array List of model types to be paginated.
     */
    protected array $modelTypes = [];

    /**
     * @var Builder The base query builder instance.
     */
    public Builder $query;

    /**
     * @var EloquentBuilder|null The union query builder instance.
     */
    public ?EloquentBuilder $unionQuery = null;

    /**
     * @var array List of scopes to be applied to the queries.
     */
    public array $scopes = [];

    /**
     * @var array List of transformers for transforming query results.
     */
    public array $transformers = [];

    /**
     * @var array List of selected columns for each model type.
     */
    protected array $selectedColumns = [];

    /**
     * @var bool Flag to prevent model retrieval.
     */
    protected bool $preventModelRetrieval = false;

    /**
     * @var array Callbacks to handle model retrieval per model type.
     */
    protected array $modelRetrievalCallbacks = [];

    /**
     * UnionPaginator constructor.
     *
     * @param array|string $modelTypes List of model types to be paginated.
     * @throws InvalidArgumentException
     */
    public function __construct(array|string $modelTypes = [])
    {
        foreach (Arr::wrap($modelTypes) as $modelType) {
            if (!is_subclass_of($modelType, Model::class)) {
                throw new InvalidArgumentException("$modelType is not a subclass of " . Model::class);
            }

            $this->addModelType($modelType);
        }
    }

    /**
     * Create a new instance for the given model types.
     *
     * @param array $modelTypes
     * @return self
     */
    public static function forModels(array $modelTypes): self
    {
        return new self($modelTypes);
    }

    /**
     * Add a model type to the paginator.
     *
     * @param string $modelType
     * @return self
     */
    public function addModelType(string $modelType): self
    {
        $this->modelTypes[] = $modelType;

        return $this;
    }

    /**
     * Prevent model retrieval during pagination.
     *
     * @return self
     */
    public function preventModelRetrieval(): self
    {
        $this->preventModelRetrieval = true;

        return $this;
    }

    /**
     * Prepare the union query for pagination.
     *
     * @return self
     * @throws BadMethodCallException
     */
    public function prepareUnionQuery(): self
    {
        $this->unionQuery = null;

        if (empty($this->modelTypes)) {
            throw new BadMethodCallException('No models have been added to the UnionPaginator.');
        }

        foreach ($this->modelTypes as $modelType) {
            /** @var Model $model */
            $model = new $modelType;
            $columns = $this->selectedColumns[$modelType] ?? $this->defaultColumns($model);

            $query = $model->newQuery()->select($columns);

            if ($this->hasScope($modelType)) {
                foreach ($this->getScopesFor($modelType) as $modelScope) {
                    $modelScope($query);
                }
            }

            if ($this->unionQuery) {
                $this->unionQuery = $this->unionQuery->union($query);
            } else {
                $this->unionQuery = $query;
            }
        }

        return $this;
    }

    /**
     * Paginate the results of the union query.
     *
     * @param int $perPage
     * @param array|string $columns
     * @param string $pageName
     * @param int|null $page
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array|string $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        if (!$this->unionQuery) {
            $this->prepareUnionQuery();
        }

        $paginated = $this->executePagination($perPage, $columns, $pageName, $page);

        $items = $paginated->items();

        if (empty($items)) {
            return $paginated;
        }

        $transformedItems = $this->preventModelRetrieval
            ? $this->transformItemsWithoutModels($items)
            : $this->transformItemsWithModels($items);

        $paginated->setCollection(collect($transformedItems));

        return $paginated;
    }

    /**
     * Execute the pagination query.
     *
     * @param int $perPage
     * @param array|string $columns
     * @param string $pageName
     * @param int|null $page
     * @return LengthAwarePaginator
     */
    protected function executePagination(int $perPage, array|string $columns, string $pageName, ?int $page): LengthAwarePaginator
    {
        $items = DB::table(DB::raw("({$this->unionQuery->toSql()}) as subquery"))
            ->mergeBindings($this->unionQuery->getQuery())
            ->forPage($page, $perPage)
            ->get($columns);

        return new LengthAwarePaginator(
            $items,
            $this->unionQuery->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => $pageName]
        );
    }

    /**
     * Transform items without retrieving models.
     *
     * @param array $items
     * @return array
     */
    protected function transformItemsWithoutModels(array $items): array
    {
        $transformedItems = [];

        foreach ($items as $item) {
            $modelType = $item->type;

            $transformedItems[] = $this->applyTransformer($modelType, $item);
        }

        return $transformedItems;
    }

    /**
     * Transform items with retrieved models.
     *
     * @param array $items
     * @return array
     */
    protected function transformItemsWithModels(array $items): array
    {
        $itemsByType = collect($items)->groupBy('type');
        $modelsByType = $this->loadModelsByType($itemsByType);

        $transformedItems = [];

        foreach ($items as $item) {
            $modelType = $item->type;
            $id = $item->id;

            $loadedModel = $modelsByType[$modelType][$id] ?? null;

            $transformedItems[] = $this->applyTransformer($modelType, $loadedModel);
        }

        return $transformedItems;
    }

    /**
     * Load models by their type.
     *
     * @param Collection $itemsByType
     * @return array
     */
    protected function loadModelsByType(Collection $itemsByType): array
    {
        $modelsByType = [];

        foreach ($itemsByType as $modelType => $groupedItems) {
            $ids = $groupedItems->pluck('id')->unique()->toArray();

            $models = $this->retrieveModels($modelType, $ids);

            $modelsByType[$modelType] = $models->keyBy(
                $models->first()?->getKeyName() ?? 'id'
            );
        }

        return $modelsByType;
    }

    /**
     * Apply a transformer to an item.
     *
     * @param string $modelType
     * @param mixed $item
     * @return mixed
     */
    protected function applyTransformer(string $modelType, $item): mixed
    {
        if (isset($this->transformers[$modelType])) {
            $callable = $this->transformers[$modelType];
            return $callable($item);
        }

        return $item;
    }

    /**
     * Register a transformer for a specific model type.
     *
     * @param string $modelType
     * @param Closure $callable
     * @return self
     */
    public function transformResultsFor(string $modelType, Closure $callable): self
    {
        if (!in_array($modelType, $this->modelTypes)) {
            return $this;
        }

        $this->transformers[$modelType] = $callable;

        return $this;
    }

    /**
     * Check if a scope exists for a model type.
     *
     * @param string $modelType
     * @return bool
     */
    public function hasScope(string $modelType): bool
    {
        return collect($this->scopes)->filter(fn ($scope) => $scope[0] === $modelType)->isNotEmpty();
    }

    /**
     * Get scopes for a specific model type.
     *
     * @param string $modelType
     * @return Collection
     */
    public function getScopesFor(string $modelType): Collection
    {
        return collect($this->scopes)->filter(fn ($scope) => $scope[0] === $modelType)->map(fn ($scope) => $scope[1]);
    }

    /**
     * Apply a scope to a model type.
     *
     * @param string $modelType
     * @param Closure $callable
     * @return self
     */
    public function applyScope(string $modelType, Closure $callable): self
    {
        $this->scopes[] = [$modelType, $callable];

        return $this;
    }

    /**
     * Retrieve models for a given type using a registered callback or default logic.
     *
     * @param string $modelType
     * @param array $ids
     * @return Collection
     */
    protected function retrieveModels(string $modelType, array $ids): Collection
    {
        if (isset($this->modelRetrievalCallbacks[$modelType])) {
            return call_user_func($this->modelRetrievalCallbacks[$modelType], $ids);
        }

        // Default retrieval logic
        return $modelType::findMany($ids);
    }

    /**
     * Register a custom callback for retrieving models by type.
     *
     * @param string $modelType
     * @param Closure $callback
     * @return self
     * @throws InvalidArgumentException
     */
    public function fetchModelsUsing(string $modelType, Closure $callback): self
    {
        if (!in_array($modelType, $this->modelTypes)) {
            throw new InvalidArgumentException("Model type {$modelType} is not registered in this paginator.");
        }

        $this->modelRetrievalCallbacks[$modelType] = $callback;

        return $this;
    }

    /**
     * Get the list of model types.
     *
     * @return array
     */
    public function getModelTypes(): array
    {
        return $this->modelTypes;
    }

    /**
     * Set the list of model types.
     *
     * @param array $modelTypes
     * @return self
     */
    public function setModelTypes(array $modelTypes): self
    {
        $this->modelTypes = $modelTypes;

        return $this;
    }

    /**
     * Set the selected columns for a specific model type.
     *
     * @param string $modelType
     * @param array $columns
     * @return self
     */
    public function setSelectedColumns(string $modelType, array $columns): self
    {
        $this->selectedColumns[$modelType] = $columns;

        return $this;
    }

    /**
     * Get the default columns for a model.
     *
     * @param Model $model
     * @return array
     */
    protected function defaultColumns(Model $model): array
    {
        $className = $model::class;

        if (!in_array(DB::getDriverName(), ['sqlite', 'pgsql'])) {
            $className = addslashes($className);
        }

        return [
            $model->getKeyName(),
            'created_at',
            'updated_at',
            DB::raw(sprintf("'%s' as type", $className))
        ];
    }

    /**
     * Handle dynamic method calls into the union query.
     *
     * @param string $method
     * @param array $parameters
     * @return $this
     */
    public function __call(string $method, array $parameters)
    {
        if (!$this->unionQuery) {
            $this->prepareUnionQuery();
        }

        $this->forwardCallTo($this->unionQuery, $method, $parameters);

        return $this;
    }
}
