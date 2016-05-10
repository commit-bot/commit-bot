<?php

namespace Kelunik\CommitBot\Queue;

use Amp\Promise;

interface Producer {
    function publish(string $message): Promise;
}