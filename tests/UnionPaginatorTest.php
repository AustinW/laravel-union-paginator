<?php

namespace AustinW\UnionPaginator\Tests;

use AustinW\UnionPaginator\Database\Factories\PostModelFactory;
use AustinW\UnionPaginator\Database\Factories\UserModelFactory;
use AustinW\UnionPaginator\Tests\TestClasses\Models\CommentModel;
use AustinW\UnionPaginator\Tests\TestClasses\Models\PostModel;
use AustinW\UnionPaginator\Tests\TestClasses\Models\TagModel;
use AustinW\UnionPaginator\Tests\TestClasses\Models\UserModel;
use AustinW\UnionPaginator\UnionPaginator;
use BadMethodCallException;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class UnionPaginatorTest extends TestCase
{
    protected UserModel $post;

    protected UserModel $comment;

    public function test_to_base_selects_from_a_union_of_the_models(): void
    {
        $query = UnionPaginator::forModels([UserModel::class, PostModel::class])->toBase();

        $this->assertInstanceOf(Builder::class, $query);

        $sql = $this->normalizeSql($query->toSql());

        $this->assertStringContainsString(' union ', $sql);
        $this->assertStringContainsString('as union_paginator', $sql);
        $this->assertStringContainsString('from user_models where user_models.deleted_at is null', $sql);
        $this->assertStringContainsString('from post_models where post_models.deleted_at is null', $sql);
    }

    // Constructor handles empty model types array
    public function test_constructor_handles_empty_model_types(): void
    {
        $paginator = new UnionPaginator();

        $this->assertEmpty($paginator->getModelTypes());
    }

    public function test_paginate_returns_a_length_aware_paginator(): void
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        UserModel::factory()->count(3)->create();
        PostModel::factory()->count(3)->create();

        $result = $paginator->paginate();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    public function test_transform_method_applies_closure_to_model_type(): void
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        // Note: Each post will create a user record
        PostModel::factory()->count(3)->create();

        $result = $paginator->transformResultsFor(UserModel::class, fn($record) => ['foo' => 'test'])->orderBy('id')->orderBy('type')->paginate();

        $this->assertEquals(PostModel::find(1), $result->items()[0]);
        $this->assertEquals(['foo' => 'test'], $result->items()[1]);
        $this->assertEquals(PostModel::find(2), $result->items()[2]);
        $this->assertEquals(['foo' => 'test'], $result->items()[3]);
        $this->assertEquals(PostModel::find(3), $result->items()[4]);
        $this->assertEquals(['foo' => 'test'], $result->items()[5]);
    }

    public function test_soft_deletes_filters_out_soft_deleted_records(): void
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        // Note: Each post will create a user record
        PostModel::factory()->count(3)->create();

        UserModel::find(1)->delete();
        PostModel::find(2)->delete();

        $this->assertSoftDeleted(UserModel::withTrashed()->find(1));
        $this->assertSoftDeleted(PostModel::withTrashed()->find(2));

        $result = $paginator->paginate();

        $this->assertEquals(4, $result->total());
    }

    public function test_static_for_method_instantiates_with_model_types_array(): void
    {
        $paginator = UnionPaginator::forModels([UserModel::class, PostModel::class]);

        $this->assertEquals([UserModel::class, PostModel::class], $paginator->getModelTypes());
    }

    public function test_paginate_handles_empty_result_set(): void
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        $result = $paginator->paginate();

        $this->assertEquals(0, $result->total());
    }

    public function test_transform_rejects_unregistered_model_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new UnionPaginator([UserModel::class, PostModel::class]))->transformResultsFor('foo', fn($record) => ['foo' => 'test']);
    }

    public function test_method_forwarding_fails_for_null_union_query()
    {
        $this->expectException(BadMethodCallException::class);

        $paginator = new UnionPaginator();

        $paginator->paginate();
    }

    public function test_soft_deletes_with_invalid_model_types_array()
    {
        $this->expectException(InvalidArgumentException::class);

        new UnionPaginator(['foo', 'bar']);
    }

    public function test_type_column_holds_the_exact_class_name()
    {
        PostModel::factory()->create();

        $types = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->preventModelRetrieval()
            ->paginate()
            ->getCollection()
            ->pluck('type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([PostModel::class, UserModel::class], $types);
    }

    public function test_paginate_respects_custom_per_page_value()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        UserModel::factory()->count(3)->create();
        PostModel::factory()->count(3)->create();

        $result = $paginator->paginate(2);

        $this->assertEquals(2, $result->perPage());
        $this->assertEquals(2, $result->count());
        $this->assertEquals(9, $result->total());
    }

    public function test_transform_closure_receives_expected_model()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        PostModel::factory()->count(3)->create();

        $result = $paginator
            ->transformResultsFor(UserModel::class, function ($record) {
                $this->assertInstanceOf(UserModel::class, $record);
                $this->assertTrue(UserModel::findOrFail($record->id)->is($record));

                return $record;
            })
            ->transformResultsFor(PostModel::class, function ($record) {
                $this->assertInstanceOf(PostModel::class, $record);
                $this->assertTrue(PostModel::findOrFail($record->id)->is($record));

                return $record;
            })
            ->paginate();

        $this->assertEquals(6, $result->total());

        $this->assertEquals([UserModel::class, PostModel::class], $paginator->getModelTypes());
    }

    public function test_pagination_maintains_proper_binding_order_for_complex_unions()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        UserModel::factory()->count(2)->create();
        PostModel::factory()->count(2)->create();

        $result = $paginator->orderBy('id')->orderBy('type')->paginate();

        $this->assertEquals(6, $result->total());
        $this->assertEquals(
            [
                PostModel::find(1),
                UserModel::find(1),
                PostModel::find(2),
                UserModel::find(2),
                UserModel::find(3),
                UserModel::find(4),
            ],
            $result->items()
        );
    }

    public function test_it_supports_ordering_models()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        $firstUser = UserModel::factory()->create(['created_at' => now()]);
        $firstPost = PostModel::factory()->for($firstUser, 'user')->create(['created_at' => now()->addSecond()]);

        $this->travel(1)->seconds();

        $secondUser = UserModel::factory()->create(['created_at' => now()->addDay()]);
        $secondPost = PostModel::factory()->for($secondUser, 'user')->create(['created_at' => now()->addDay()->addSecond()]);

        $result = $paginator->latest()->paginate();

        $items = $result->items();

        $this->assertEquals($secondPost->id, $items[0]->id);
        $this->assertEquals(PostModel::class, get_class($items[0]));

        $this->assertEquals($secondUser->id, $items[1]->id);
        $this->assertEquals(UserModel::class, get_class($items[1]));

        $this->assertEquals($firstPost->id, $items[2]->id);
        $this->assertEquals(PostModel::class, get_class($items[2]));

        $this->assertEquals($firstUser->id, $items[3]->id);
        $this->assertEquals(UserModel::class, get_class($items[3]));
    }

    public function test_empty_closure_for_transform(): void
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        PostModel::factory()->count(3)->create();

        $result = $paginator->transformResultsFor(UserModel::class, fn($record) => null)->orderBy('id')->orderBy('type')->paginate();

        $items = $result->items();

        $this->assertInstanceOf(PostModel::class, $items[0]);
        $this->assertNull($items[1]);
        $this->assertInstanceOf(PostModel::class, $items[2]);
        $this->assertNull($items[3]);
        $this->assertInstanceOf(PostModel::class, $items[4]);
        $this->assertNull($items[5]);
    }

    public function test_multiple_transforms_on_same_model(): void
    {
        $paginator = new UnionPaginator([UserModel::class]);

        UserModel::factory()->count(3)->create();

        $paginator->transformResultsFor(UserModel::class, fn($record) => (object) ['id' => $record->id, 'transformed' => true]);
        $result = $paginator->transformResultsFor(UserModel::class, fn($record) => (object) ['id' => $record->id, 'overridden' => true])->paginate();

        foreach ($result->items() as $item) {
            $itemArray = (array) $item;
            $this->assertArrayHasKey('overridden', $itemArray);
            $this->assertArrayNotHasKey('transformed', $itemArray);
        }
    }

    public function test_union_with_large_tables(): void
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        PostModel::factory()->count(100)->create();

        $result = $paginator->paginate(50);

        $this->assertEquals(50, $result->count());
        $this->assertEquals(200, $result->total());
    }

    public function test_scope_columns_and_fetch_reject_unregistered_model_types(): void
    {
        $paginator = new UnionPaginator([UserModel::class]);

        foreach ([
            fn () => $paginator->applyScope(PostModel::class, fn ($query) => $query),
            fn () => $paginator->setSelectedColumns(PostModel::class, ['id']),
            fn () => $paginator->fetchModelsUsing(PostModel::class, fn (array $ids) => collect()),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_boundary_conditions_for_pagination(): void
    {
        $paginator = new UnionPaginator([UserModel::class]);

        UserModel::factory()->count(10)->create();

        $result = $paginator->paginate(10);

        $this->assertEquals(10, $result->total());
        $this->assertEquals(10, $result->perPage());
        $this->assertEquals(1, $result->currentPage());

        $result = $paginator->paginate(5);

        $this->assertEquals(2, $result->lastPage());
    }

    public function test_pagination_beyond_total_results(): void
    {
        $paginator = new UnionPaginator([UserModel::class]);

        UserModel::factory()->count(5)->create();

        $result = $paginator->paginate(5, ['*'], 'page', 2);

        $this->assertEmpty($result->items());
        $this->assertEquals(0, $result->count());
        $this->assertEquals(5, $result->total());
    }

    public function test_combined_sorting_rules(): void
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        $users = UserModel::factory()->count(3)->create(['created_at' => now()->subMinutes(10)]);

        $posts = PostModel::factory()
            ->count(3)
            ->state(new Sequence(
                ['user_id' => $users[0]->id],
                ['user_id' => $users[1]->id],
                ['user_id' => $users[2]->id],
            ))
            ->create(['created_at' => now()->subMinutes(5)]);

        $items = $paginator->latest()->paginate()->items();

        $this->assertCount(6, $items);

        // Ensure the sorting order by created_at
        $this->assertEquals(PostModel::class, get_class($items[0])); // Posts should be first (newer timestamps)
        $this->assertEquals(UserModel::class, get_class($items[3])); // Explicit Users follow (older timestamps)

        // Further assertions for clarity
        foreach ($posts as $post) {
            $this->assertContains($post->id, array_column($items, 'id'));
        }

        foreach ($users as $user) {
            $this->assertContains($user->id, array_column($items, 'id'));
        }
    }

    public function test_it_can_scope_queries_by_model()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        $users = collect([
            UserModel::factory()->create(['name' => 'Sarah Whitaker']),
            UserModel::factory()->create(['name' => 'James Holden']),
            UserModel::factory()->create(['name' => 'Maya Patel']),
        ]);

        PostModel::factory()
            ->count(3)
            ->state(new Sequence(
                ['user_id' => $users[0]->id],
                ['user_id' => $users[1]->id],
                ['user_id' => $users[2]->id],
            ))
            ->create(['created_at' => now()->subMinutes(5)]);

        $items = $paginator->applyScope(UserModel::class, fn($query) => $query->where('name', 'like', '%Sarah%'))
            ->orderBy('id')
            ->orderBy('type')
            ->paginate()
            ->items();

        $this->assertCount(4, $items);

        $this->assertEquals(PostModel::class, get_class($items[0]));
        $this->assertEquals(UserModel::class, get_class($items[1]));
        $this->assertEquals(PostModel::class, get_class($items[2]));
        $this->assertEquals(PostModel::class, get_class($items[3]));
    }

    public function test_it_can_scope_queries_by_model_with_multiple_scopes()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        $users = collect([
            UserModel::factory()->create(['name' => 'Sarah Whitaker']),
            UserModel::factory()->create(['name' => 'James Holden']),
            UserModel::factory()->create(['name' => 'Maya Patel']),
        ]);

        PostModel::factory()
            ->count(3)
            ->state(new Sequence(
                ['user_id' => $users[0]->id],
                ['user_id' => $users[1]->id, 'created_at' => now()->subMinutes(5)],
                ['user_id' => $users[2]->id, 'created_at' => now()->subMinutes(10)],
            ))
            ->create();

        $items = $paginator
            ->applyScope(UserModel::class, fn($query) => $query->where('name', 'like', '%Sarah%'))
            ->applyScope(PostModel::class, fn($query) => $query->where('created_at', '<', now()->subMinutes(4)))
            ->orderBy('id')
            ->orderBy('type')
            ->paginate()
            ->items();

        $this->assertCount(3, $items);

        $this->assertEquals(UserModel::class, get_class($items[0]));

        $this->assertEquals(PostModel::class, get_class($items[1]));
        $this->assertEquals(PostModel::class, get_class($items[2]));
    }

    public function test_it_optimizes_retrieval_by_finding_models_in_mass_query()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        // Create multiple records for each model type.
        UserModel::factory()->count(5)->create();
        PostModel::factory()->count(5)->create();

        // Enable the query log to count queries.
        DB::enableQueryLog();

        // Add transformations to force model retrieval rather than returning raw records.
        $paginator->transformResultsFor(UserModel::class, fn($model) => $model);
        $paginator->transformResultsFor(PostModel::class, fn($model) => $model);

        $result = $paginator->orderBy('id')->orderBy('type')->paginate(10);

        // Retrieve the logged queries.
        $queries = DB::getQueryLog();

        // We want to ensure only one query per model type after pagination is called.
        // Let's count how many times we query user_models and post_models by ID.
        $userModelQueries = 0;
        $postModelQueries = 0;

        foreach ($queries as $query) {
            // Check which tables are being queried by looking at the SQL.
            $sql = $this->normalizeSql($query['query']);
            if (str_contains($sql, 'from user_models where user_models.id in')) {
                $userModelQueries++;
            }
            if (str_contains($sql, 'from post_models where post_models.id in')) {
                $postModelQueries++;
            }
        }

        // Assert that we only ran one bulk lookup per model type.
        $this->assertEquals(1, $userModelQueries, 'Expected exactly one bulk query for user models.');
        $this->assertEquals(1, $postModelQueries, 'Expected exactly one bulk query for post models.');

        // Also verify that we got all the items.
        $this->assertCount(10, $result->items(), 'Should retrieve all 10 items.');
        foreach ($result->items() as $item) {
            $this->assertNotNull($item, 'Each item should be a loaded model instance.');
        }
    }

    public function test_multiple_scopes_for_same_model_type()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        UserModel::factory()
            ->count(3)
            ->state(new Sequence(
                ['name' => 'Anna', 'created_at' => now()->subHour()],
                ['name' => 'Aaron'],
                ['name' => 'Bob'],
                ['name' => 'Charlie']
            ))
            ->create();

        // Scope 1: Only users with 'a' in their name
        $paginator->applyScope(UserModel::class, fn($query) => $query->where('name', 'like', '%a%'));

        // Scope 2: Only users created in the last hour
        $paginator->applyScope(UserModel::class, fn($query) => $query->where('created_at', '>', now()->subHour()));

        $result = $paginator->paginate();

        $this->assertCount(1, $result->items());

        // We can assert the filtered set size, or other conditions
        // For simplicity, let's ensure that the returned items pass the conditions.
        foreach ($result->items() as $item) {
            if ($item instanceof UserModel) {
                $this->assertStringContainsString('a', $item->name);
                $this->assertTrue($item->created_at->greaterThan(now()->subHour()));
            }
        }
    }

    public function test_no_transformations_returns_models_directly()
    {
        $paginator = new UnionPaginator([UserModel::class, PostModel::class]);

        PostModel::factory()->count(2)->create();

        $result = $paginator->paginate();

        $this->assertCount(4, $result->items());

        foreach ($result->items() as $item) {
            $this->assertTrue($item instanceof UserModel || $item instanceof PostModel);
        }
    }

    public function test_prevent_model_retrieval_returns_raw_records()
    {
        $posts = PostModel::factory()->count(2)->create();

        // Paginate with preventModelRetrieval enabled
        $paginator = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->preventModelRetrieval()
            ->paginate(10);

        $items = $paginator->items();
        $this->assertCount(4, $items);

        // Ensure the items are raw records
        foreach ($items as $item) {
            $this->assertInstanceOf(stdClass::class, $item);
            $this->assertTrue(property_exists($item, 'id'));
            $this->assertTrue(property_exists($item, 'type'));
        }
    }

    public function test_prevent_model_retrieval_applies_transformations_to_raw_records()
    {
        PostModel::factory()->count(2)->create();

        CommentModel::factory()
            ->for(UserModel::first(), 'user')
            ->for(PostModel::first(), 'post')
            ->create();

        // Add a transformation for UserModel
        // Instead of returning the raw record, we return a simple array containing the record's id and a custom flag
        $paginator = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->transformResultsFor(UserModel::class, function ($rawRecord) {
                return UserModel::with('comments')->find($rawRecord->id);
            })
            ->preventModelRetrieval()
            ->paginate(10);

        $items = $paginator->items();
        $this->assertCount(4, $items);

        // Ensure the transformation was applied to the raw records
        foreach ($items as $item) {
            if ($item->type === UserModel::class) {
                $this->assertIsArray($item);
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('comments', $item);
                $this->assertCount(1, $item['comments']);
            }
        }

        // Ensure the transformation was not applied to the PostModel records
        foreach ($items as $item) {
            if ($item->type === PostModel::class) {
                $this->assertInstanceOf(stdClass::class, $item);
                $this->assertTrue(property_exists($item, 'id'));
                $this->assertTrue(property_exists($item, 'type'));
            }
        }
    }

    public function test_prevent_model_retrieval_avoids_n_plus_one_queries()
    {
        PostModel::factory()->count(3)->create();

        DB::enableQueryLog();

        $paginator = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->preventModelRetrieval()
            ->paginate(10);

        $items = $paginator->items();
        $this->assertCount(6, $items);

        // Get the logged queries
        $queries = DB::getQueryLog();
        $sqlStatements = array_column($queries, 'query');

        // We should not see any queries like 'select * from "user_models" where "id" in (...)' or
        // 'select * from "post_models" where "id" in (...)' after the initial union query.
        // We only expect the union pagination queries, not additional findMany() calls.
        foreach ($sqlStatements as $sql) {
            $this->assertStringNotContainsString('from user_models where user_models.id in', $this->normalizeSql($sql));
            $this->assertStringNotContainsString('from post_models where post_models.id in', $this->normalizeSql($sql));
        }
    }

    public function test_fetch_models_using_retains_loaded_models()
    {
        // When using the `fetchModelsUsing` method, the paginator should assume that models have already been retrieved
        // and use those models directly instead of querying the database again.

        // We can test this by creating a user model with many posts and then paginating over the user model.
        // We will then use the `fetchModelsUsing` method to provide the user model and its posts to the paginator.

        UserModel::factory()
            ->has(PostModel::factory()->count(3), 'posts')
            ->create();

        $this->assertEquals(1, UserModel::count());
        $this->assertEquals(3, PostModel::count());

        $paginator = UnionPaginator::forModels([UserModel::class]);

        $paginator->fetchModelsUsing(UserModel::class, function (array $records) {
            return UserModel::with('posts')->findMany($records);
        });

        $items = $paginator->paginate()->items();

        // We expect that posts has already been loaded on the model
        $this->assertTrue($items[0]->relationLoaded('posts'));
        $this->assertCount(3, $items[0]->posts);
    }

    public function test_fetch_models_using_global_scopes()
    {
        // When the paginated models utilise global scopes, the paginator should correctly apply those scopes.

        $comment = CommentModel::factory()
            ->recycle(UserModel::factory()->create(['email' => 'foo@test.com']))
            ->create();

        $comment2 = CommentModel::factory()
            ->recycle(UserModel::factory()->create(['email' => 'bar@test.com']))
            ->create();

        $paginator = UnionPaginator::forModels([PostModelWithGlobalScope::class, CommentModelWithGlobalScope::class]);

        $items = $paginator->orderBy('id')->orderBy('type')->paginate()->items();

        $this->assertCount(2, $items);

        $this->assertEquals(CommentModelWithGlobalScope::class, get_class($items[0]));
        $this->assertEquals($comment->id, $items[0]->id);

        $this->assertEquals(PostModelWithGlobalScope::class, get_class($items[1]));
        $this->assertEquals($comment->post_id, $items[1]->id);
    }

    public function test_paginates_on_the_models_connection_not_the_default_one()
    {
        // Note: Each post will create a user record
        PostModel::factory()->count(2)->create();

        // Point the "pinned" connection at the seeded database, then make the default an empty one.
        config(['database.connections.pinned' => config('database.connections.'.config('database.default'))]);
        config(['database.connections.empty' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::connection('pinned')->setPdo(DB::connection()->getPdo());
        $default = config('database.default');
        config(['database.default' => 'empty']);

        $result = UnionPaginator::forModels([PinnedUserModel::class, PinnedPostModel::class])->paginate();

        config(['database.default' => $default]);

        $this->assertEquals(4, $result->total());
        $this->assertCount(4, $result->items());
    }

    public function test_forwarded_where_applies_to_every_model()
    {
        UserModel::factory()->create(['created_at' => now()->subDays(2)]);
        PostModel::factory()->create(['created_at' => now()->subDays(2)]); // also creates a recent user
        PostModel::factory()->create();

        $items = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->where('created_at', '<', now()->subDay())
            ->paginate()
            ->items();

        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(
            [UserModel::class, PostModel::class],
            array_map('get_class', $items)
        );
    }

    public function test_configuration_after_a_forwarded_call_still_applies()
    {
        PostModel::factory()->count(2)->create();

        $result = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->latest()
            ->applyScope(UserModel::class, fn ($query) => $query->whereRaw('1 = 0'))
            ->paginate();

        $this->assertEquals(2, $result->total());
        $this->assertContainsOnlyInstancesOf(PostModel::class, $result->items());
    }

    public function test_unsupported_forwarded_method_throws()
    {
        $this->expectException(BadMethodCallException::class);

        UnionPaginator::forModels([UserModel::class])->groupBy('type');
    }

    public function test_when_receives_the_paginator()
    {
        PostModel::factory()->create();

        $result = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->when(true, fn (UnionPaginator $paginator) => $paginator->applyScope(UserModel::class, fn ($query) => $query->whereRaw('1 = 0')))
            ->paginate();

        $this->assertContainsOnlyInstancesOf(PostModel::class, $result->items());
    }

    public function test_simple_paginate_hydrates_models_without_counting()
    {
        PostModel::factory()->count(3)->create();

        DB::enableQueryLog();

        $result = UnionPaginator::forModels([UserModel::class, PostModel::class])->simplePaginate(4);

        $this->assertCount(4, $result->items());
        $this->assertTrue($result->hasMorePages());
        foreach ($result->items() as $item) {
            $this->assertTrue($item instanceof UserModel || $item instanceof PostModel);
        }
        $this->assertQueryLogDoesntContain('count(*)');
    }

    public function test_cursor_paginate_walks_every_row_once()
    {
        $users = UserModel::factory()->count(3)->create(['created_at' => now()->subHour()]);
        PostModel::factory()->count(3)->for($users[0], 'user')->create(['created_at' => now()->subHour()]);

        $paginator = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->latest()
            ->orderBy('type')
            ->orderBy('id');

        $seen = [];
        $cursor = null;

        do {
            $page = $paginator->cursorPaginate(4, ['*'], 'cursor', $cursor);

            foreach ($page->items() as $item) {
                $seen[] = $item::class.':'.$item->id;
            }

            $cursor = $page->nextCursor();
        } while ($cursor);

        $this->assertCount(6, $seen);
        $this->assertCount(6, array_unique($seen));
    }

    public function test_models_with_custom_keys_and_no_timestamps()
    {
        $tag = TagModel::create(['name' => 'laravel']);
        $user = UserModel::factory()->create();

        $items = UnionPaginator::forModels([TagModel::class, UserModel::class])
            ->orderBy('type')
            ->paginate()
            ->items();

        $this->assertCount(2, $items);
        $this->assertTrue($items[0]->is($tag));
        $this->assertTrue($items[1]->is($user));
    }

    public function test_rows_without_a_loaded_model_are_skipped()
    {
        UserModel::factory()->count(3)->create();

        $items = UnionPaginator::forModels([UserModel::class])
            ->fetchModelsUsing(UserModel::class, fn (array $ids) => UserModel::findMany($ids)->where('id', '!=', 2))
            ->orderBy('id')
            ->paginate()
            ->items();

        $this->assertEquals([1, 3], array_map(fn ($user) => $user->id, $items));
    }

    public function test_duplicate_model_types_are_ignored()
    {
        $paginator = new UnionPaginator([UserModel::class, UserModel::class]);
        $paginator->addModelType(UserModel::class);

        $this->assertEquals([UserModel::class], $paginator->getModelTypes());
    }

    public function test_set_model_types_validates_classes()
    {
        $this->expectException(InvalidArgumentException::class);

        (new UnionPaginator())->setModelTypes([UserModel::class, 'stdClass']);
    }

    public function test_eloquent_only_where_methods_are_rejected()
    {
        $this->expectException(BadMethodCallException::class);

        UnionPaginator::forModels([UserModel::class])->whereHas('posts');
    }

    public function test_models_on_different_connections_are_rejected()
    {
        config(['database.connections.pinned' => config('database.connections.'.config('database.default'))]);

        $this->expectException(InvalidArgumentException::class);

        UnionPaginator::forModels([UserModel::class, PinnedPostModel::class])->toBase();
    }

    public function test_scoped_joins_do_not_duplicate_rows()
    {
        $user = UserModel::factory()->create();
        PostModel::factory()->count(2)->for($user, 'user')->create();

        $result = UnionPaginator::forModels([UserModel::class, CommentModel::class])
            ->applyScope(UserModel::class, fn ($query) => $query->join('post_models', 'post_models.user_id', '=', 'user_models.id'))
            ->paginate();

        $this->assertEquals(1, $result->total());
        $this->assertCount(1, $result->items());
    }

    public function test_several_models_without_timestamps_union_with_timestamped_ones()
    {
        TagModel::create(['name' => 'laravel']);
        UserModel::factory()->create();

        $result = UnionPaginator::forModels([TagModel::class, OtherTagModel::class, UserModel::class])->paginate();

        $this->assertEquals(3, $result->total());
    }

    public function test_cursor_continues_past_a_page_whose_models_were_all_skipped()
    {
        UserModel::factory()->count(4)->create();

        $paginator = UnionPaginator::forModels([UserModel::class])
            ->fetchModelsUsing(UserModel::class, fn (array $ids) => UserModel::findMany($ids)->whereNotIn('id', [1, 2]))
            ->orderBy('id');

        $first = $paginator->cursorPaginate(2);

        $this->assertCount(0, $first->items());
        $this->assertNotNull($first->nextCursor());

        $second = $paginator->cursorPaginate(2, ['*'], 'cursor', $first->nextCursor());

        $this->assertEquals([3, 4], array_map(fn ($user) => $user->id, $second->items()));
    }

    public function test_cursor_works_with_transformed_items_in_both_directions()
    {
        PostModel::factory()->count(3)->create();

        $paginator = UnionPaginator::forModels([UserModel::class, PostModel::class])
            ->transformResultsFor(UserModel::class, fn ($user) => 'user '.$user->id)
            ->transformResultsFor(PostModel::class, fn ($post) => 'post '.$post->id)
            ->orderBy('id');

        $first = $paginator->cursorPaginate(4);
        $second = $paginator->cursorPaginate(4, ['*'], 'cursor', $first->nextCursor());
        $back = $paginator->cursorPaginate(4, ['*'], 'cursor', $second->previousCursor());

        $this->assertEquals(['post 1', 'user 1', 'post 2', 'user 2'], $first->items());
        $this->assertEquals(['post 3', 'user 3'], $second->items());
        $this->assertEquals($first->items(), $back->items());
    }

    public function test_custom_columns_are_selected_in_order_with_type_added_last()
    {
        $post = PostModel::factory()->create(['title' => 'Hello']);
        CommentModel::factory()->for($post, 'post')->for($post->user, 'user')->create(['content' => 'Hi']);

        $rows = UnionPaginator::forModels([PostModel::class, CommentModel::class])
            ->setSelectedColumns(PostModel::class, ['post_models.id as id', 'post_models.title as label'])
            ->setSelectedColumns(CommentModel::class, ['comment_models.id as id', 'comment_models.content as label'])
            ->preventModelRetrieval()
            ->orderBy('type')
            ->paginate()
            ->items();

        $this->assertEquals(['label' => 'Hi', 'type' => CommentModel::class], ['label' => $rows[0]->label, 'type' => $rows[0]->type]);
        $this->assertEquals(['label' => 'Hello', 'type' => PostModel::class], ['label' => $rows[1]->label, 'type' => $rows[1]->type]);

        $models = UnionPaginator::forModels([PostModel::class, CommentModel::class])
            ->setSelectedColumns(PostModel::class, ['post_models.id as id', 'post_models.title as label'])
            ->setSelectedColumns(CommentModel::class, ['comment_models.id as id', 'comment_models.content as label'])
            ->orderBy('type')
            ->paginate()
            ->items();

        $this->assertInstanceOf(CommentModel::class, $models[0]);
        $this->assertTrue($models[1]->is($post));
    }

    public function test_cursor_back_to_the_first_page_has_no_previous_page()
    {
        UserModel::factory()->count(6)->create();

        $paginator = UnionPaginator::forModels([UserModel::class])->orderBy('id');

        $first = $paginator->cursorPaginate(2);
        $second = $paginator->cursorPaginate(2, ['*'], 'cursor', $first->nextCursor());
        $back = $paginator->cursorPaginate(2, ['*'], 'cursor', $second->previousCursor());

        $this->assertEquals([1, 2], array_map(fn ($user) => $user->id, $back->items()));
        $this->assertTrue($back->onFirstPage());
        $this->assertNull($back->previousCursor());
        $this->assertNotNull($back->nextCursor());
    }

    public function test_cursor_rejects_raw_random_and_expression_orders()
    {
        $paginators = [
            UnionPaginator::forModels([UserModel::class])->orderByRaw('id desc'),
            UnionPaginator::forModels([UserModel::class])->inRandomOrder(),
            UnionPaginator::forModels([UserModel::class])->orderBy(DB::raw('id'), 'desc'),
        ];

        foreach ($paginators as $paginator) {
            try {
                $paginator->cursorPaginate();
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_raw_row_transformers_cannot_change_the_cursor()
    {
        UserModel::factory()->count(4)->create();

        $paginator = UnionPaginator::forModels([UserModel::class])
            ->preventModelRetrieval()
            ->transformResultsFor(UserModel::class, function ($row) {
                $row->id += 100;

                return $row;
            })
            ->orderBy('id');

        $first = $paginator->cursorPaginate(2);
        $second = $paginator->cursorPaginate(2, ['*'], 'cursor', $first->nextCursor());

        $this->assertEquals([103, 104], array_map(fn ($row) => $row->id, $second->items()));
    }
}

class OtherTagModel extends TagModel
{
    public $table = 'tag_models';
}

class PinnedUserModel extends UserModel
{
    protected $connection = 'pinned';

    public $table = 'user_models';
}

class PinnedPostModel extends PostModel
{
    protected $connection = 'pinned';

    public $table = 'post_models';
}

#[ScopedBy(TestScope::class)]
class PostModelWithGlobalScope extends PostModel
{
    public $table = 'post_models';
}

#[ScopedBy(TestScope::class)]
class CommentModelWithGlobalScope extends CommentModel
{
    public $table = 'comment_models';
}

class TestScope implements Scope
{
    public function apply(EloquentBuilder $builder, Model $model): void
    {
        $builder->whereRelation('user', 'email', 'foo@test.com');
    }
}
