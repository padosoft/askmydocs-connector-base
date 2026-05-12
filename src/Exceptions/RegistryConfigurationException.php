<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Exceptions;

use Padosoft\AskMyDocsConnectorBase\ConnectorInterface;

/**
 * Raised at boot when a configured connector FQCN does not implement
 * {@see ConnectorInterface}, or when
 * the config shape is otherwise malformed.
 *
 * Mirrors R23 / PipelineRegistry pattern: fail loudly + early instead
 * of crashing later with a cryptic "undefined method" fatal the first
 * time a controller asks the registry for a connector.
 */
class RegistryConfigurationException extends \RuntimeException {}
