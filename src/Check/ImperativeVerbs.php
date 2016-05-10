<?php

namespace Kelunik\CommitBot\Check;

use Kelunik\CommitBot\Check;

class ImperativeVerbs implements Check {
    function check(string $commitMessage): array {
        $firstWord = explode(" ", $commitMessage, 2)[0];

        if (preg_match('~ed|ing$~i', $firstWord)) {
            return [
                "Use verbs in imperative style like 'Merge' or 'Revert' instead of 'Merged' or 'Reverted'.",
            ];
        }

        return [];
    }
}