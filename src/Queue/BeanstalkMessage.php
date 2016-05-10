<?php

namespace Kelunik\CommitBot\Queue;

use Amp\Promise;

class BeanstalkMessage implements Message {
    private $id;
    private $message;
    private $acknowledgeCallback;

    public function __construct(int $id, string $message, callable $acknowledgeCallback) {
        $this->id = $id;
        $this->message = $message;
        $this->acknowledgeCallback = $acknowledgeCallback;
    }

    public function acknowledge(): Promise {
        $callback = $this->acknowledgeCallback;

        return $callback();
    }

    public function getBody(): string {
        return $this->message;
    }

    public function getId(): int {
        return $this->id;
    }
}