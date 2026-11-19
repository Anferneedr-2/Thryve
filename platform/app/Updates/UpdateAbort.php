<?php

namespace App\Updates;

use RuntimeException;

/** A failed check that happens before the live site is touched. */
class UpdateAbort extends RuntimeException
{
}
