<?php

namespace App\Services\LinkedResources;

use RuntimeException;

class CaptureException extends RuntimeException
{
    // Only internal, fixed codes are allowed; never include provider text or URLs.
}
