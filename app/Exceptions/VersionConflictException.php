<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A caller tried to save on top of a version that is no longer current (R-28 §31): someone or
 * some platform moved the document on since they read it. The save is refused before anything
 * is written — the caller re-reads the current content and decides, instead of a silent
 * last-write-wins.
 */
class VersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('The document has moved on to version '.$currentVersion.'.');
    }
}
