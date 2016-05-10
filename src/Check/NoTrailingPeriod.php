<?php

namespace Kelunik\CommitBot\Check;

use Kelunik\CommitBot\Check;

class NoTrailingPeriod implements Check {
    function check(string $commitMessage): array {
        $subject = explode("\n", $commitMessage, 2)[0];
        $lastLetter = mb_substr($subject, -1, 1);

        if ($lastLetter === ".") {
            return [
                "Message subject must not end with a period."
            ];
        }

        return [];
    }
}