<?php

/**
 * IronCart_Scan — payload-too-large signal.
 *
 * Raised by {@see UploadPayloadBuilder} when findings or composer packages
 * exceed the server's 413 cutoffs. Caught by {@see UploadRunner} so the
 * operator sees a clear "payload would exceed server limit" message
 * without the upload ever leaving the host.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

use RuntimeException;

/**
 * Thrown when module-side payload size guards would exceed the server's 413 cutoff.
 */
class PayloadTooLargeException extends RuntimeException
{
}
