<?php

namespace Kelunik\CommitBot\Check;

use Kelunik\CommitBot\Check;

class FirstLetterUppercase implements Check {
    function check(string $commitMessage): array {
        $firstLetter = mb_substr($commitMessage, 0, 1);

        if ($firstLetter !== mb_strtoupper($firstLetter)) {
            return [
                "Commit message should start with an uppercase letter."
            ];
        }

        return [];
    }
}