<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An import file was rejected. The message is shown to the user as is, so it
 * must say what is wrong with the file and never echo a value from it.
 */
class ImportException extends RuntimeException {}
