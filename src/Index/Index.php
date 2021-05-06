<?php

declare(strict_types=1);

/*
 * This file is part of BoringSearch.
 *
 * (c) Yanick Witschi
 *
 * @license MIT
 */

namespace BoringSearch\MeiliSearch\Index;

use BoringSearch\Core\Document\Attribute\Attribute;
use BoringSearch\Core\Document\Attribute\AttributeCollection;
use BoringSearch\Core\Document\Document;
use BoringSearch\Core\Document\DocumentInterface;
use BoringSearch\Core\Index\AbstractIndex;
use BoringSearch\Core\Index\AsynchronousResultsIndexInterface;
use BoringSearch\Core\Index\Result\AsynchronousResult;
use BoringSearch\Core\Index\Result\AsynchronousResultInterface;
use BoringSearch\Core\Index\Result\ResultInterface;
use BoringSearch\Core\Index\Result\SynchronousResult;
use BoringSearch\Core\Query\QueryInterface;
use BoringSearch\Core\Query\Result\QueryResult;
use BoringSearch\Core\Query\Result\QueryResultInterface;
use BoringSearch\Core\Query\Result\Result;
use BoringSearch\MeiliSearch\Adapter\MeiliSearch;
use MeiliSearch\Client;
use MeiliSearch\Endpoints\Indexes;
use MeiliSearch\Exceptions\ApiException;

class Index extends AbstractIndex implements AsynchronousResultsIndexInterface
{
    private Client $client;

    public function __construct(Client $client, MeiliSearch $adapter, string $name)
    {
        $this->client = $client;

        parent::__construct($adapter, $name);
    }

    public function query(QueryInterface $query): QueryResultInterface
    {
        $attributesToRetrieve = $query->getAttributeNamesToRetrieve();

        if ([] === $attributesToRetrieve) {
            $attributesToRetrieve = ['*'];
        }

        // Make sure we always retrieve the id
        $attributesToRetrieve = array_unique(array_merge($attributesToRetrieve, ['id']));

        $searchResult = $this->getIndex()->search($query->getSearchString(), [
            'limit' => $query->getLimit(),
            'offset' => $query->getOffset(),
            'attributesToRetrieve' => $attributesToRetrieve,
        ]);

        $matches = [];

        foreach ($searchResult as $result) {
            $matches[] = new Result($this->createDocumentFromResult($result));
        }

        return new QueryResult($query, $matches, $searchResult->getHitsCount(), $searchResult->getExhaustiveNbHits());
    }

    public function findByIdentifier(string $identifier): ?DocumentInterface
    {
        try {
            return $this->createDocumentFromResult($this->getIndex()->getDocument($identifier));
        } catch (ApiException $e) {
            return null;
        }
    }

    public function doDelete(array $identifiers): AsynchronousResultInterface
    {
        $result = $this->getIndex()->deleteDocuments($identifiers);

        return new AsynchronousResult(true, (string) $result['updateId']);
    }

    public function doPurge(): AsynchronousResultInterface
    {
        $result = $this->getIndex()->deleteAllDocuments();

        return new AsynchronousResult(true, (string) $result['updateId']);
    }

    public function waitForAsynchronousResult(AsynchronousResultInterface $asynchronousResult): ResultInterface
    {
        $result = $this->getIndex()->waitForPendingUpdate((int) $asynchronousResult->getTaskIdentifier());

        if ('processed' === $result['status']) {
            return new SynchronousResult(true);
        }

        return new SynchronousResult(false);
    }

    /**
     * @param array<DocumentInterface> $documents
     */
    protected function doIndex(array $documents): AsynchronousResultInterface
    {
        $data = [];

        foreach ($documents as $document) {
            $data[] = array_merge($document->getAttributes()->toArray(), [
                'id' => $document->getIdentifier(),
            ]);
        }

        $result = $this->getIndex()->addDocuments($data);

        return new AsynchronousResult(true, (string) $result['updateId']);
    }

    private function createDocumentFromResult(array $result): DocumentInterface
    {
        $attributes = new AttributeCollection();

        foreach ($result as $k => $v) {
            if ('id' === $k) {
                continue;
            }

            $attributes->addAttribute(new Attribute($k, $v));
        }

        return new Document($result['id'], $attributes);
    }

    private function getIndex(): Indexes
    {
        return $this->client->getOrCreateIndex($this->getName());
    }
}
