<?php

namespace Kelunik\CommitBot\Check;

use Kelunik\CommitBot\Check;

class Length implements Check {
    function check(string $commitMessage): array {
        if (mb_strlen($commitMessage) < 10) {
            return [
                "Commit message too short, at least 10 characters required.",
            ];
        }

        return [];
    }
}