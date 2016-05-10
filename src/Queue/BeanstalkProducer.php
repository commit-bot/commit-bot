<?php

namespace Kelunik\CommitBot\Queue;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Promise;

class BeanstalkProducer implements Producer {
    private $name;
    private $client;

    public function __construct(string $name, BeanstalkClient $client) {
        $this->name = $name;
        $this->client = $client;
    }

    function publish(string $message): Promise {
        return $this->client->put($message);
    }
}