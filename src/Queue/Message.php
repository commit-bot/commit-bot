<?php

namespace Kelunik\CommitBot\Queue;

use Amp\Promise;

interface Message {
    function acknowledge(): Promise;
    function getId(): int;
    function getBody(): string;
}