
# UnionPaginator Documentation

[![Tests](https://github.com/AustinW/laravel-union-paginator/actions/workflows/run-tests.yml/badge.svg)](https://github.com/AustinW/laravel-union-paginator/actions/workflows/run-tests.yml)
[![Linter](https://github.com/AustinW/laravel-union-paginator/actions/workflows/run-linter.yml/badge.svg)](https://github.com/AustinW/laravel-union-paginator/actions/workflows/run-linter.yml)
<a href="https://packagist.org/packages/austinw/laravel-union-paginator"><img src="https://img.shields.io/packagist/dt/austinw/laravel-union-paginator" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/austinw/laravel-union-paginator"><img src="https://img.shields.io/packagist/v/austinw/laravel-union-paginator" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/austinw/laravel-union-paginator"><img src="https://img.shields.io/packagist/l/austinw/laravel-union-paginator" alt="License"></a>

## Introduction

The `UnionPaginator` package enables you to paginate and unify results from multiple Eloquent models into a single dataset. By merging multiple model queries, it allows for straightforward pagination and sorting of data drawn from various sources.

**Key Features:**
- Unite and paginate multiple Eloquent model results in one go.
- Apply per-model filters (scopes) before the union.
- Choose between retrieving actual Eloquent models or working with raw database records.
- Mitigate N+1 queries by loading models in bulk.
- Customize selected columns for each model type.

## Installation

Install via Composer:

```bash
composer require austinw/laravel-union-paginator
```

Requires PHP 8.2+ and Laravel 10, 11, 12 or 13.

## Migration Guide

If you are upgrading from an earlier version of `UnionPaginator`, please refer to the [Migration Guide](MIGRATION.md) for detailed instructions on updating your code to take advantage of the latest features and improvements.

## Getting Started

### Initializing the UnionPaginator

Specify which Eloquent models you want to combine:

```php
use AustinW\UnionPaginator\UnionPaginator;

$paginator = UnionPaginator::forModels([User::class, Post::class]);
```

All provided classes must be subclasses of `Illuminate\Database\Eloquent\Model`.

### Paginating Data

Call `paginate` to get paginated results:

```php
$results = $paginator->paginate(15);
```

This returns a `LengthAwarePaginator` instance, seamlessly integrating with Laravel’s pagination utilities.

### Applying Scopes to Individual Models

You can apply specific query conditions to a single model type before creating the union:

```php
$paginator->applyScope(User::class, fn($query) => $query->where('active', true));
```

Now only active users are included in the final union.

### Customizing Mass Model Retrieval
The UnionPaginator class allows you to customize how models are retrieved during pagination. This can be useful if you need to apply specific logic or optimizations when fetching models from the database.

#### Registering a Custom Retrieval Callback
You can register a custom callback for retrieving models by type using the fetchModelsUsing method. This method allows you to define how models should be fetched for a specific model type.

```php
use AustinW\UnionPaginator\UnionPaginator;
use App\Models\Post;
use App\Models\Comment;

// Create a new UnionPaginator instance for the specified models
$paginator = new UnionPaginator([Post::class, Comment::class]);

// Register a custom retrieval callback for the Post model
$paginator->fetchModelsUsing(Post::class, function (array $ids) {
    // Custom logic to retrieve Post models
    return Post::with('author')->findMany($ids);
});

// Register a custom retrieval callback for the Comment model
$paginator->fetchModelsUsing(Comment::class, function (array $ids) {
    // Custom logic to retrieve Comment models
    return Comment::with('post')->findMany($ids);
});

// Use the paginator as usual
$paginatedResults = $paginator->paginate();
```

#### Important Considerations
- Model Type Registration: Ensure that the model type you are registering a callback for has been added to the UnionPaginator instance using the constructor or addModelType method.
- Callback Signature: The callback should accept an array of IDs and return a collection of models. You can use Eloquent's findMany method or any other custom logic to retrieve the models.
- Default Behavior: If no custom callback is registered for a model type, the UnionPaginator will use the default retrieval logic, which is to call findMany on the model type.

By using custom retrieval callbacks, you can optimize and tailor the model fetching process to suit your application's specific needs.

### Transforming Results

Use `transformResultsFor` to alter records for a particular model type:

```php
$paginator->transformResultsFor(User::class, fn($user) => [
    'id' => $user->id,
    'uppercase_name' => strtoupper($user->name),
]);
```

If model retrieval is active, `$user` is an Eloquent model. If you call `preventModelRetrieval()`, `$user` is a raw database record (`stdClass`).

### Preventing Model Retrieval

If you don’t need Eloquent models and prefer raw records:

```php
$paginator->preventModelRetrieval()->paginate();
```

Transformations still apply, but are run on raw records.

### Selecting Columns

Choose specific columns for each model type to reduce overhead:

```php
$paginator->setSelectedColumns(User::class, ['id', 'created_at', 'updated_at', DB::raw(DB::getPdo()->quote(User::class).' as type')]);
```

Every model must select the same number of columns (a SQL `UNION` requirement), and `type` must hold the model's class name unless you call `preventModelRetrieval()`.

### Soft Deletes

Models using `SoftDeletes` are automatically filtered so that soft-deleted records do not appear.

## Methods

- **forModels(array $modelTypes): self**  
  Set the models to combine. Throws an exception if a non-model class is provided.

- **applyScope(string \$modelType, Closure $callable): self**  
  Modify queries for an individual model type.

- **transformResultsFor(string \$modelType, Closure $callable): self**  
  Apply transformations to either models or raw records of a particular model type.

- **preventModelRetrieval(): self**  
  Skip loading actual models. Return raw database rows instead.

- **setSelectedColumns(string \$modelType, array $columns): self**  
  Specify which columns to fetch for each model type.

- **paginate(\$perPage = 15, \$columns = ['*'], \$pageName = 'page', $page = null): LengthAwarePaginator**  
  Execute the union query, apply scopes and transformations, and return a paginator.

- **__call(\$method, $parameters)**  
  Forward method calls to the underlying union query builder, enabling sorting and other query modifications.

## Example Usage

```php
use AustinW\UnionPaginator\UnionPaginator;

$paginator = UnionPaginator::forModels([User::class, Post::class])
    ->applyScope(User::class, fn($query) => $query->where('active', true))
    ->transformResultsFor(User::class, fn($user) => ['id' => $user->id, 'name' => strtoupper($user->name)])
    ->transformResultsFor(Post::class, fn($post) => ['title' => $post->title, 'date' => $post->created_at->toDateString()])
    ->paginate(10);

foreach ($paginator->items() as $item) {
    // Each $item could be a transformed array or a raw record, depending on your configuration.
}
```

## Advanced Usage

### Ordering and Complex Queries

You can chain Eloquent methods before `paginate()`:

```php
$paginator->latest()->paginate();
```

or

```php
$paginator->orderBy('created_at', 'desc')->paginate();
```

### Multiple Transformations for the Same Model

Applying multiple transformations for the same model type overwrites earlier ones:

```php
$paginator->transformResultsFor(User::class, fn($user) => ['transformed' => true])
          ->transformResultsFor(User::class, fn($user) => ['overridden' => true]);
```

The latter transformation takes precedence.

### Handling Empty Results

If no matching records are found, the paginator returns an empty result set without errors.

## Testing

`UnionPaginator` is well-tested across various scenarios, including:
- Multiple model unions.
- Soft deletes handling.
- Both raw and model-based transformations.
- Large datasets and edge cases.
