<?php

namespace App\Parity\Watch;

/**
 * One place the watch looks at.
 */
interface Source
{
    /**
     * The prefix of the keys of its findings.
     */
    public function name(): string;

    /**
     * @return list<Finding>
     */
    public function findings(): array;
}
