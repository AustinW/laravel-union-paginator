<?php

namespace AustinW\UnionPaginator\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase($this->app);

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AustinW\\UnionPaginator\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function setUpDatabase(Application $app)
    {
        $app['db']->connection()->getSchemaBuilder()->create('user_models', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->string('password');

            $table->softDeletes();
            $table->timestamps();
        });

        $app['db']->connection()->getSchemaBuilder()->create('post_models', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('title');
            $table->text('content');

            $table->softDeletes();
            $table->timestamps();
        });

        $app['db']->connection()->getSchemaBuilder()->create('tag_models', function (Blueprint $table) {
            $table->increments('tag_id');
            $table->string('name');
        });

        $app['db']->connection()->getSchemaBuilder()->create('comment_models', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('post_id');
            $table->text('content');

            $table->timestamps();
        });
    }

    protected function assertQueryLogContains(string $partialSql)
    {
        $queryLog = collect(DB::getQueryLog())->pluck('query')->implode('|');

        $this->assertTrue(Str::contains($queryLog, $partialSql), "Query log did not contain partial SQL: `{$partialSql}`. Query log: {$queryLog}");
    }

    protected function assertQueryLogDoesntContain(string $partialSql)
    {
        $queryLog = collect(DB::getQueryLog())->pluck('query')->implode('|');

        $this->assertFalse(Str::contains($queryLog, $partialSql), "Query log contained partial SQL: `{$partialSql}`");
    }

    protected function normalizeSql(string $sql): string
    {
        return strtolower(str_replace(['"', '`', '(', ')'], '', $sql));
    }

    public function sortCallback(Builder $query, $descending): void
    {
        $query->orderBy('name', $descending ? 'DESC' : 'ASC');
    }

    public function filterCallback(Builder $query, $value): void
    {
        $query->where('name', $value);
    }
}
