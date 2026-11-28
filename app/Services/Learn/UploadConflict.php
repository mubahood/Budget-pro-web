<?php

namespace App\Services\Learn;

/** A chunk arrived out of order: the uploader resends from the offset in the message. */
class UploadConflict extends \RuntimeException
{
}
