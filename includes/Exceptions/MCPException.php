<?php
/**
 * Exception alias compatibility class.
 *
 * @package SEOMCPGuard\Exceptions
 */

declare( strict_types=1 );

namespace SEOMCPGuard\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MCPException
 *
 * Aliases or extends \SEOMCPGuard\MCPException so both namespaces work seamlessly.
 */
class MCPException extends \SEOMCPGuard\MCPException {
}
