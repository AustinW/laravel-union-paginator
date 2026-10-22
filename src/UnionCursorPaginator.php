<?php

namespace AustinW\UnionPaginator;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

/**
 * A cursor paginator whose cursors come from the raw union rows, not from the hydrated items.
 * Hydrated items can be models without a `type` attribute, transformed values, or fewer than the rows.
 * The two cursor methods mirror AbstractCursorPaginator, reading $rows instead of $items.
 *
 * @extends CursorPaginator<int, mixed>
 */
final class UnionCursorPaginator extends CursorPaginator
{
    /**
     * @var Collection<int, mixed>
     */
    protected Collection $rows;

    /**
     * @param CursorPaginator<int, mixed> $paginator
     * @param Collection<int, mixed> $items
     */
    public static function fromRows(CursorPaginator $paginator, Collection $items): self
    {
        // Built empty, so the constructor does not reverse the (already ordered) rows a second time.
        $instance = new self([], $paginator->perPage(), $paginator->cursor(), $paginator->getOptions());
        // Not hasMorePages(): that is always true when paging backwards.
        $instance->hasMore = $paginator->hasMore;
        $instance->rows = $paginator->getCollection();
        $instance->setCollection($items);

        return $instance;
    }

    public function previousCursor(): ?Cursor
    {
        if (is_null($this->cursor) || ($this->cursor->pointsToPreviousItems() && ! $this->hasMore) || $this->rows->isEmpty()) {
            return null;
        }

        return $this->getCursorForItem($this->rows->first(), false);
    }

    public function nextCursor(): ?Cursor
    {
        if ((is_null($this->cursor) && ! $this->hasMore) ||
            (! is_null($this->cursor) && $this->cursor->pointsToNextItems() && ! $this->hasMore) ||
            $this->rows->isEmpty()) {
            return null;
        }

        return $this->getCursorForItem($this->rows->last(), true);
    }
}
