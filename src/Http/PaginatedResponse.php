<?php

namespace JarredCain\CanvasLms\Http;

use Countable;
use IteratorAggregate;
use ArrayIterator;
use Traversable;
use Illuminate\Support\LazyCollection;

class PaginatedResponse implements Countable, IteratorAggregate
{
    private ?string $nextUrl = null;
    private ?string $prevUrl = null;
    private ?string $firstUrl = null;
    private ?string $lastUrl = null;

    /** @var array<string, true> URLs already fetched in this pagination sequence */
    private array $visitedUrls = [];

    public function __construct(
        private array $items,
        private string $modelClass,
        private CanvasClient $client,
        string $linkHeader = '',
        array $visitedUrls = []
    ) {
        $this->visitedUrls = $visitedUrls;
        $this->parseLinkHeader($linkHeader);
    }

    public static function fromResponse(
        Response $response,
        string $modelClass,
        CanvasClient $client,
        array $visitedUrls = []
    ): static {
        $data = $response->json();

        // Canvas returns a JSON array for list endpoints
        if (!array_is_list($data)) {
            $data = [$data];
        }

        $items = array_map(
            fn(array $attrs) => (new $modelClass)->fill($attrs),
            $data
        );

        return new static(
            $items,
            $modelClass,
            $client,
            $response->header('Link') ?: $response->header('link'),
            $visitedUrls
        );
    }

    public function items(): array
    {
        return $this->items;
    }

    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function hasNextPage(): bool
    {
        return $this->nextUrl !== null;
    }

    public function hasPrevPage(): bool
    {
        return $this->prevUrl !== null;
    }

    public function next(): ?static
    {
        if (!$this->nextUrl) {
            return null;
        }

        // Guard against infinite pagination loops (e.g., same URL returned twice)
        if (isset($this->visitedUrls[$this->nextUrl])) {
            return null;
        }

        $visited = $this->visitedUrls;
        $visited[$this->nextUrl] = true;

        // Use the opaque URL directly — never reconstruct Canvas pagination URLs
        $response = $this->client->getUrl($this->nextUrl);

        return static::fromResponse($response, $this->modelClass, $this->client, $visited);
    }

    public function prev(): ?static
    {
        if (!$this->prevUrl) {
            return null;
        }

        $response = $this->client->getUrl($this->prevUrl);

        return static::fromResponse($response, $this->modelClass, $this->client);
    }

    public function lazy(): LazyCollection
    {
        return LazyCollection::make(function () {
            $page = $this;

            foreach ($page->items() as $item) {
                yield $item;
            }

            while ($page->hasNextPage()) {
                $page = $page->next();
                foreach ($page->items() as $item) {
                    yield $item;
                }
            }
        });
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    private function parseLinkHeader(string $header): void
    {
        if (empty($header)) {
            return;
        }

        foreach (explode(',', $header) as $part) {
            $part = trim($part);

            if (!preg_match('/<([^>]+)>\s*;\s*rel="([^"]+)"/i', $part, $matches)) {
                continue;
            }

            $url = $matches[1];
            $rel = strtolower($matches[2]);

            match ($rel) {
                'next'    => $this->nextUrl = $url,
                'prev'    => $this->prevUrl = $url,
                'first'   => $this->firstUrl = $url,
                'last'    => $this->lastUrl = $url,
                default   => null,
            };
        }
    }
}
