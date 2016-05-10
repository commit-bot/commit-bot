<?php

namespace Kelunik\CommitBot\Check;

use Kelunik\CommitBot\Check;

class ImperativeVerbs implements Check {
    function check(string $commitMessage): array {
        $words = explode(" ", $commitMessage);

        if (preg_match('~ed|ing|^adds$~i', $words[0])) {
            return [
                "Use verbs in imperative style like 'Merge' or 'Revert' instead of 'Merged' or 'Reverting'.",
            ];
        }

        if (preg_match('~^(the|a) ~i', $words[0])) {
            return [
                "Use verbs in imperative style like 'Merge' or 'Revert' at the beginning.",
            ];
        }

        if (count($words) < 2) {
            return [
                "Commit messages should at least consist of two words, first a imperative verb and then another word."
            ];
        }

        return [];
    }
}