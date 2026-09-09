<?php
declare(strict_types=1);

// Minimal stand-ins for the Kanboard core classes McpServer depends on,
// so the plugin class can be exercised without a Kanboard installation.

namespace Kanboard\Core;

class Base
{
    protected $container;
}

namespace Kanboard\Model;

class TaskModel
{
    public const STATUS_OPEN = 1;
    public const STATUS_CLOSED = 0;
}

namespace Kanboard\Filter;

class TaskProjectFilter
{
    public $value;

    public function __construct($value = null)
    {
        $this->value = $value;
    }
}

namespace Kanboard\Plugin\ModelContextProtocol\Core;

require __DIR__ . '/../Core/McpServer.php';
