# Laravel Union Paginator

[![Tests](https://github.com/AustinW/laravel-union-paginator/actions/workflows/run-tests.yml/badge.svg)](https://github.com/AustinW/laravel-union-paginator/actions/workflows/run-tests.yml)
[![Static Analysis](https://github.com/AustinW/laravel-union-paginator/actions/workflows/run-linter.yml/badge.svg)](https://github.com/AustinW/laravel-union-paginator/actions/workflows/run-linter.yml)
<a href="https://packagist.org/packages/austinw/laravel-union-paginator"><img src="https://img.shields.io/packagist/dt/austinw/laravel-union-paginator" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/austinw/laravel-union-paginator"><img src="https://img.shields.io/packagist/v/austinw/laravel-union-paginator" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/austinw/laravel-union-paginator"><img src="https://img.shields.io/packagist/l/austinw/laravel-union-paginator" alt="License"></a>

Paginate several Eloquent models as one list — an activity feed of posts, comments and users, for example — with a single SQL `UNION`, and get the models back in order.

```php
use AustinW\UnionPaginator\UnionPaginator;

$feed = UnionPaginator::forModels([Post::class, Comment::class])
    ->latest()
    ->paginate(20);

foreach ($feed as $item) {
    // $item is a Post or a Comment model, newest first.
}
```

## Installation

```bash
composer require austinw/laravel-union-paginator
```

Requires PHP 8.2+ and Laravel 12 or 13. Upgrading from 2.x? See the [migration guide](MIGRATION.md).

## How it works

1. Each model becomes one `SELECT` of four columns: `id` (the primary key), `created_at`, `updated_at` and `type` (the model's class name). Global scopes and soft deletes apply.
2. The selects are combined with `UNION` and wrapped in a derived table, `union_paginator`.
3. Your `where` and `orderBy` calls run on that derived table, so they apply to **every** model.
4. The page of rows is turned back into models with one `findMany()` per model type.

## Filtering and ordering the whole union

Query builder `where*` and `orderBy*` methods, `latest()`, `oldest()`, `reorder()` and `inRandomOrder()` are forwarded to the union. They can only see the union's columns (`id`, `created_at`, `updated_at`, `type`, plus any you select).

```php
UnionPaginator::forModels([Post::class, Comment::class])
    ->where('created_at', '>=', now()->subWeek())
    ->whereIn('type', [Post::class, Comment::class])
    ->orderByDesc('created_at')
    ->paginate();
```

When you order, `type` and `id` are appended as tie-breakers so pages stay stable when sort values are equal. Without an order, the database decides the row order — add one if the order matters.

Calls can come in any order: the query is built when you paginate. `when()` and `unless()` receive the paginator:

```php
$paginator->when($onlyPosts, fn ($paginator) => $paginator->where('type', Post::class));
```

`where(fn ($query) => ...)` closures receive a plain query builder for the derived table, not an Eloquent builder.

## Constraining one model

Use `applyScope()` for conditions on a single model, including relationship queries such as `whereHas()`:

```php
$paginator->applyScope(Post::class, fn ($query) => $query->where('published', true))
          ->applyScope(Comment::class, fn ($query) => $query->whereHas('post'));
```

The callback receives that model's Eloquent builder. Call `applyScope()` more than once to add more conditions.

## Pagination styles

```php
$paginator->paginate(15);          // LengthAwarePaginator, with a total
$paginator->simplePaginate(15);    // next/previous only, skips the COUNT over the union
$paginator->cursorPaginate(15);    // cursor pagination for large or infinite feeds
```

Cursor pagination needs an order on non-null columns. `type` and `id` are appended automatically, so `->latest()->cursorPaginate()` works as long as `created_at` is never null.

## Loading models

By default each page runs one `findMany()` per model type. To eager load or customise the query, use `fetchModelsUsing()`:

```php
$paginator->fetchModelsUsing(Post::class, fn (array $ids) => Post::with('author')->findMany($ids));
```

The callback receives the keys on the current page and returns the models. Rows whose model is not returned (for example, deleted between the two queries) are left out of the page.

## Raw rows

Skip model loading and get the union rows (`stdClass`) instead:

```php
$paginator->preventModelRetrieval()->paginate();
```

## Transforming results

```php
$paginator->transformResultsFor(Post::class, fn (Post $post) => [
    'title' => $post->title,
    'date' => $post->created_at->toDateString(),
]);
```

The callback receives the model, or the raw row when model retrieval is prevented. A later call for the same model replaces the earlier one.

## Custom columns

Replace a model's default select with `setSelectedColumns()`. Columns in a `UNION` are matched by **position**, so every model must select the same number of columns in the same order. Alias the key as `id`. The paginator always adds `type` as the last column.

```php
$paginator
    ->setSelectedColumns(Post::class, ['posts.id as id', 'posts.published_at as created_at', 'posts.title as title'])
    ->setSelectedColumns(Comment::class, ['comments.id as id', 'comments.created_at', 'comments.body as title']);
```

Models without timestamp columns should set `const CREATED_AT = null;` and `const UPDATED_AT = null;` — the paginator then selects `NULL` for them.

## Limitations

- All models must use the same database connection.
- Primary keys must have compatible types across models (Postgres will not union an integer with a UUID). Cast them with `setSelectedColumns()` if needed.

## Testing

```bash
composer test
composer analyse
```

CI runs the suite on PHP 8.2–8.5 with Laravel 12 and 13 on SQLite, plus MySQL 8.4 and Postgres 17.

## License

MIT. See [LICENSE.md](LICENSE.md).
