<?php

namespace Kelunik\CommitBot\Check;

use Kelunik\CommitBot\Check;

class Blacklist implements Check {
    private $blacklist = [
        "fix test" => "Which test did you fix?",
        "add test" => "What's tested?",
        "news entry" => "What's new?",
        "add news entry" => "What's new?",
        "update news" => "What's new?",
        "add news" => "What's new?",
        "fix build" => "What was the problem?",
        "fix memory leak" => "Where did we leak memory?",
        "fix merge" => "What did you / somebody fuck up?",
        "fix segfault" => "Where did it segfault?",
    ];

    public function check(string $commitMessage): array {
        $subject = mb_strtolower(explode("\n", $commitMessage, 2)[0]);

        if (isset($this->blacklist[$subject])) {
            return [
                $this->blacklist[$subject]
            ];
        }

        return [];
    }
}