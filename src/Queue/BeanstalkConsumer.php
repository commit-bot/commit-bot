<?php

namespace Kelunik\CommitBot\Queue;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Deferred;
use Amp\Promise;
use Amp\PromiseStream;

class BeanstalkConsumer implements Consumer {
    private $deferred;
    private $stream;
    private $client;
    private $size;
    private $usage;

    public function __construct(BeanstalkClient $client, int $size = 1) {
        $this->deferred = new Deferred;
        $this->stream = new PromiseStream($this->deferred->promise());
        $this->client = $client;
        $this->size = $size;
    }

    public function watch(string $tube = "default"): Promise {
        return $this->client->watch($tube)->when(function ($error) {
            if ($error) {
                $this->deferred->fail($error);
            } else {
                $this->allocate();
            }
        });
    }

    public function getStream(): PromiseStream {
        return $this->stream;
    }

    private function allocate(): Promise {
        $this->usage++;

        $promise = $this->client->reserve();
        $promise->when(function ($error, $args) {
            if ($error) {
                $this->deferred->fail($error);

                return;
            }

            $this->deferred->update(new BeanstalkMessage($args[0], $args[1], function() use ($args) {
                return $this->client->delete($args[0])->when(function() {
                    $this->usage--;

                    $this->allocate();
                });
            }));

            if ($this->usage < $this->size) {
                $this->allocate();
            }
        });

        return $promise;
    }

    public function getSize(): int {
        return $this->size;
    }

    public function getUsage(): int {
        return $this->usage;
    }
}