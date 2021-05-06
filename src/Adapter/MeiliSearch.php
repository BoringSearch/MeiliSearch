<?php

declare(strict_types=1);

/*
 * This file is part of BoringSearch.
 *
 * (c) Yanick Witschi
 *
 * @license MIT
 */

namespace BoringSearch\MeiliSearch\Adapter;

use BoringSearch\Core\Adapter\AbstractAdapter;
use BoringSearch\Core\Index\IndexInterface;
use BoringSearch\MeiliSearch\Index\Index;
use MeiliSearch\Client;

class MeiliSearch extends AbstractAdapter
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function getIndex(string $name): IndexInterface
    {
        return new Index($this->client, $this, $name);
    }
}
