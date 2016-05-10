<?php

namespace Kelunik\CommitBot;

interface Check {
    function check(string $commitMessage): array;
}
