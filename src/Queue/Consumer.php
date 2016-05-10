<?php

namespace Kelunik\CommitBot\Queue;

use Amp\Promise;
use Amp\PromiseStream;

interface Consumer {
    function watch(string $tube): Promise;
    function getStream(): PromiseStream;
    function getSize(): int;
    function getUsage(): int;
}