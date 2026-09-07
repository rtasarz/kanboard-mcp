<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Core;

use Kanboard\Core\Base;
use Kanboard\Model\ColumnModel;
use Kanboard\Model\TaskModel;
use InvalidArgumentException;
use Throwable;
use Traversable;

/**
 * MCP Server for Kanboard
 * Implements Model Context Protocol JSON-RPC 2.0 specification
 * Provides comprehensive project management tools for AI assistants
 */
class McpServer extends Base
{
    private const LATEST_PROTOCOL_VERSION = '2025-11-25';

    /**
     * Maintained for compatibility with clients that still negotiate older revisions.
     *
     * @var array<int,string>
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    public function __construct($container)
    {
        $this->container = $container;
    }

    /**
     * Handle MCP JSON-RPC request
     */
    public function handleRequest(array $request): ?array
    {
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return $this->errorResponse(-32600, 'Invalid Request', isset($request['id']) ? $request['id'] : null);
        }

        if (isset($request['params']) && !is_array($request['params'])) {
            return $this->errorResponse(-32602, 'Invalid params', isset($request['id']) ? $request['id'] : null);
        }

        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        try {
            switch ($method) {
                case 'initialize':
                    return $this->initialize($params, $id);

                case 'notifications/initialized':
                case 'initialized':
                    return $this->initialized();

                case 'tools/list':
                    return $this->listTools($params, $id);

                case 'tools/call':
                    return $this->callTool($params, $id);

                case 'resources/list':
                    return $this->listResources($params, $id);

                case 'resources/templates/list':
                    return $this->listResourceTemplates($params, $id);

                case 'resources/read':
                    return $this->readResource($params, $id);

                case 'ListOfferings':
                case 'listOfferings':
                    return $this->listOfferings($id);

                case 'ping':
                    return $this->ping($id);

                default:
                    return $this->errorResponse(-32601, 'Method not found: ' . $method, $id);
            }
        } catch (Throwable $exception) {
            $this->logThrowable('Unhandled MCP request failure', $exception);
            return $this->errorResponse(-32603, 'Internal error', $id);
        }
    }

    /**
     * Initialize MCP server
     */
    private function initialize(array $params, int|string|null $id): array
    {
        try {
            $requestedVersion = isset($params['protocolVersion']) && is_string($params['protocolVersion'])
                ? $params['protocolVersion']
                : null;

            $negotiatedVersion = in_array($requestedVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)
                ? $requestedVersion
                : self::LATEST_PROTOCOL_VERSION;

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => $negotiatedVersion,
                    'capabilities' => [
                        'tools' => [
                            'listChanged' => false,
                        ],
                        'resources' => [
                            'listChanged' => false,
                        ],
                    ],
                    'serverInfo' => [
                        'name' => 'kanboard-mcp',
                        'title' => 'Kanboard MCP Server',
                        'version' => '1.0.0',
                        'description' => 'Kanboard project-management tools and resources via MCP.',
                    ],
                    'instructions' => 'Use the available Kanboard tools to manage projects, tasks, columns, categories, and swimlanes.',
                ]
            ];
        } catch (Throwable $exception) {
            $this->logThrowable('Initialize failed', $exception);
            return $this->errorResponse(-32603, 'Initialize failed', $id);
        }
    }

    /**
     * Handle initialized notification
     */
    private function initialized(): ?array
    {
        // This is a notification, so we don't return a response
        return null;
    }

    /**
     * List available tools
     */
    private function listTools(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        $tools = [
            [
                'name' => 'get_projects',
                'description' => 'Get all projects',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ]
            ],
            [
                'name' => 'create_project',
                'description' => 'Create a new project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Project name'],
                        'description' => ['type' => 'string', 'description' => 'Project description']
                    ],
                    'required' => ['name']
                ]
            ],
            [
                'name' => 'duplicate_project',
                'description' => 'Duplicate a project (columns and swimlanes are always copied; optional parts are selected via copy_* flags)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Source project ID'],
                        'name' => ['type' => 'string', 'description' => 'New project name (defaults to "<source name> (Clone)")'],
                        'identifier' => ['type' => 'string', 'description' => 'New project identifier, alphanumeric, stored uppercased'],
                        'owner_id' => ['type' => 'integer', 'description' => 'Owner user ID; also added as project manager (0 for none)'],
                        'is_private' => ['type' => 'boolean', 'description' => 'Force the copy to be private (otherwise inherits the source project)'],
                        'copy_permissions' => ['type' => 'boolean', 'description' => 'Copy user/group access (default true)'],
                        'copy_project_roles' => ['type' => 'boolean', 'description' => 'Copy custom roles (default true)'],
                        'copy_categories' => ['type' => 'boolean', 'description' => 'Copy categories (default true)'],
                        'copy_tags' => ['type' => 'boolean', 'description' => 'Copy tags (default true)'],
                        'copy_actions' => ['type' => 'boolean', 'description' => 'Copy automatic actions (default true)'],
                        'copy_custom_filters' => ['type' => 'boolean', 'description' => 'Copy custom filters (default true)'],
                        'copy_metadata' => ['type' => 'boolean', 'description' => 'Copy metadata (default false)'],
                        'copy_tasks' => ['type' => 'boolean', 'description' => 'Copy tasks (default false)']
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'get_tasks',
                'description' => 'Get tasks from a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'status_id' => [
                            'type' => 'integer',
                            'description' => 'Status ID (1 for active, 0 for archived)',
                            'enum' => [TaskModel::STATUS_CLOSED, TaskModel::STATUS_OPEN],
                            'default' => TaskModel::STATUS_OPEN
                        ]
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'create_task',
                'description' => 'Create a new task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'title' => ['type' => 'string', 'description' => 'Task title'],
                        'description' => ['type' => 'string', 'description' => 'Task description'],
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID'],
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID (default swimlane if omitted)']
                    ],
                    'required' => ['project_id', 'title']
                ]
            ],
            [
                'name' => 'update_task',
                'description' => 'Update an existing task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'title' => ['type' => 'string', 'description' => 'Task title'],
                        'description' => ['type' => 'string', 'description' => 'Task description'],
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'get_columns',
                'description' => 'Get columns for a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'move_task',
                'description' => 'Move a task to a different column or swimlane',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'column_id' => ['type' => 'integer', 'description' => 'Target column ID'],
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Target swimlane ID (keeps current if omitted)']
                    ],
                    'required' => ['project_id', 'task_id', 'column_id']
                ]
            ],
            [
                'name' => 'get_task_details',
                'description' => 'Get detailed information about a specific task (verbose: true adds project, column, swimlane, and category names)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'verbose' => ['type' => 'boolean', 'description' => 'Include project, column, swimlane, and category names (default: false)']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'delete_task',
                'description' => 'Delete a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'assign_task',
                'description' => 'Assign a task to a user',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'user_id' => ['type' => 'integer', 'description' => 'User ID']
                    ],
                    'required' => ['task_id', 'user_id']
                ]
            ],
            [
                'name' => 'set_task_due_date',
                'description' => 'Set due date for a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'due_date' => ['type' => 'string', 'description' => 'Due date in YYYY-MM-DD format']
                    ],
                    'required' => ['task_id', 'due_date']
                ]
            ],
            [
                'name' => 'add_task_comment',
                'description' => 'Add a comment to a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'comment' => ['type' => 'string', 'description' => 'Comment text']
                    ],
                    'required' => ['task_id', 'comment']
                ]
            ],
            [
                'name' => 'get_users',
                'description' => 'Get all users in the system',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ]
            ],
            [
                'name' => 'get_task_comments',
                'description' => 'Get all comments for a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            // Administrative Tools - Column Management
            [
                'name' => 'create_column',
                'description' => 'Add new columns to projects',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'title' => ['type' => 'string', 'description' => 'Column title'],
                        'task_limit' => ['type' => 'integer', 'description' => 'Task limit (0 for unlimited)'],
                        'description' => ['type' => 'string', 'description' => 'Column description']
                    ],
                    'required' => ['project_id', 'title']
                ]
            ],
            [
                'name' => 'update_column',
                'description' => 'Modify column settings',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID'],
                        'title' => ['type' => 'string', 'description' => 'Column title'],
                        'task_limit' => ['type' => 'integer', 'description' => 'Task limit (0 for unlimited)'],
                        'description' => ['type' => 'string', 'description' => 'Column description'],
                        'hide_in_dashboard' => ['type' => 'integer', 'description' => '1 to hide from dashboard, 0 to show']
                    ],
                    'required' => ['column_id']
                ]
            ],
            [
                'name' => 'delete_column',
                'description' => 'Remove columns',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID']
                    ],
                    'required' => ['column_id']
                ]
            ],
            [
                'name' => 'reorder_columns',
                'description' => 'Change column positions',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'column_ids' => ['type' => 'array', 'description' => 'Array of column IDs in desired order']
                    ],
                    'required' => ['project_id', 'column_ids']
                ]
            ],
            // Administrative Tools - Category Management
            [
                'name' => 'create_category',
                'description' => 'Add task categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'name' => ['type' => 'string', 'description' => 'Category name'],
                        'description' => ['type' => 'string', 'description' => 'Category description']
                    ],
                    'required' => ['project_id', 'name']
                ]
            ],
            [
                'name' => 'update_category',
                'description' => 'Modify categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'Category ID'],
                        'name' => ['type' => 'string', 'description' => 'Category name'],
                        'description' => ['type' => 'string', 'description' => 'Category description']
                    ],
                    'required' => ['category_id']
                ]
            ],
            [
                'name' => 'delete_category',
                'description' => 'Remove categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'Category ID']
                    ],
                    'required' => ['category_id']
                ]
            ],
            [
                'name' => 'get_categories',
                'description' => 'List project categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            // Administrative Tools - Swimlane Management
            [
                'name' => 'create_swimlane',
                'description' => 'Add swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'name' => ['type' => 'string', 'description' => 'Swimlane name'],
                        'description' => ['type' => 'string', 'description' => 'Swimlane description']
                    ],
                    'required' => ['project_id', 'name']
                ]
            ],
            [
                'name' => 'update_swimlane',
                'description' => 'Modify swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID'],
                        'name' => ['type' => 'string', 'description' => 'Swimlane name'],
                        'description' => ['type' => 'string', 'description' => 'Swimlane description']
                    ],
                    'required' => ['swimlane_id']
                ]
            ],
            [
                'name' => 'delete_swimlane',
                'description' => 'Remove swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID']
                    ],
                    'required' => ['project_id', 'swimlane_id']
                ]
            ],
            [
                'name' => 'get_swimlanes',
                'description' => 'List project swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            // Automatic Actions
            [
                'name' => 'get_project_actions',
                'description' => 'List automatic actions for a project. params_resolved uses the same id lookups as the Automatic actions page (dangling ids show as "?")',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'get_available_actions',
                'description' => 'List available automatic actions (class name => description)',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ]
            ],
            [
                'name' => 'get_compatible_action_events',
                'description' => 'List events compatible with an automatic action (event name => description)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'action_name' => ['type' => 'string', 'description' => 'Automatic action class name (from get_available_actions)']
                    ],
                    'required' => ['action_name']
                ]
            ],
            [
                'name' => 'create_action',
                'description' => 'Create an automatic action. params keys are defined per action class (Kanboard app/Action/*.php); values are strings, e.g. {"duration":"7","src_column_id":"141","dest_column_id":"133"}',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'event_name' => ['type' => 'string', 'description' => 'Event name (from get_compatible_action_events)'],
                        'action_name' => ['type' => 'string', 'description' => 'Action class name from get_available_actions (includes leading backslash)'],
                        'params' => ['type' => 'object', 'description' => 'Action parameters as a key/value object']
                    ],
                    'required' => ['project_id', 'event_name', 'action_name', 'params']
                ]
            ],
            [
                'name' => 'remove_action',
                'description' => 'Remove an automatic action by id (cleanup path for dangling actions after a column delete)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'action_id' => ['type' => 'integer', 'description' => 'Action ID']
                    ],
                    'required' => ['action_id']
                ]
            ],
            [
                'name' => 'get_project_tags',
                'description' => 'List project tags (id, name, color_id, project_id)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'get_colors',
                'description' => 'List available colors (color_id => name). Used for tag/task/category color_id.',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ]
            ],
            [
                'name' => 'create_tag',
                'description' => 'Create a project tag. Optional color_id from get_colors.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'name' => ['type' => 'string', 'description' => 'Tag name'],
                        'color_id' => ['type' => 'string', 'description' => 'Color id from get_colors (omit for no color)']
                    ],
                    'required' => ['project_id', 'name']
                ]
            ],
            [
                'name' => 'update_tag',
                'description' => 'Rename a tag and/or set its color_id (omit a field to keep it)',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tag_id' => ['type' => 'integer', 'description' => 'Tag ID'],
                        'name' => ['type' => 'string', 'description' => 'New tag name'],
                        'color_id' => ['type' => 'string', 'description' => 'Color id from get_colors (empty string clears color)']
                    ],
                    'required' => ['tag_id']
                ]
            ],
            [
                'name' => 'remove_tag',
                'description' => 'Remove a project tag',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tag_id' => ['type' => 'integer', 'description' => 'Tag ID']
                    ],
                    'required' => ['tag_id']
                ]
            ],
            [
                'name' => 'set_task_tags',
                'description' => 'Set tags on a task by name (creates missing tags). replace=false adds; replace=true replaces the set. Returns new_tags [{id, name}] created this call.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'tags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Tag names'
                        ],
                        'replace' => ['type' => 'boolean', 'description' => 'Replace existing tags (default false = add)']
                    ],
                    'required' => ['task_id', 'tags']
                ]
            ]
        ];

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => $tools
            ]
        ];
    }

    /**
     * Call a tool
     */
    private function callTool(array $params, int|string|null $id): array
    {
        if (!isset($params['name']) || !is_string($params['name']) || trim($params['name']) === '') {
            return $this->errorResponse(-32602, 'Invalid params: name is required and must be a non-empty string', $id);
        }

        if (array_key_exists('arguments', $params) && !is_array($params['arguments'])) {
            return $this->errorResponse(-32602, 'Invalid params: arguments must be an object', $id);
        }

        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        try {
            $result = null;

            switch ($toolName) {
                case 'get_projects':
                    $projects = $this->container['projectModel']->getAll();
                    $result = array_values($projects);
                    break;

                case 'create_project':
                    if (!isset($arguments['name']) || !is_string($arguments['name']) || trim($arguments['name']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: name must be a non-empty string', $id);
                    }

                    $projectId = $this->container['projectModel']->create([
                        'name' => trim($arguments['name']),
                        'description' => $arguments['description'] ?? ''
                    ]);
                    $result = ['project_id' => $projectId];
                    break;

                case 'duplicate_project':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $projectId = $this->duplicateProject($arguments);

                    if ($projectId === false || $projectId === null || (int) $projectId <= 0) {
                        return $this->createToolExecutionErrorResponse('Failed to duplicate project', $id);
                    }

                    $result = ['project_id' => (int) $projectId];
                    break;

                case 'get_tasks':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : null;
                    $statusId = array_key_exists('status_id', $arguments)
                        ? (int) $arguments['status_id']
                        : TaskModel::STATUS_OPEN;

                    if ($projectId === null || $projectId <= 0 || !in_array(
                        $statusId,
                        [TaskModel::STATUS_OPEN, TaskModel::STATUS_CLOSED],
                        true
                    )) {
                        return $this->createToolExecutionErrorResponse(
                            'Invalid arguments: project_id and status_id must be integers (status_id in [0,1])',
                            $id
                        );
                    }

                    try {
                        $tasks = $this->container['taskFinderModel']->getAll($projectId, $statusId);
                        $result = $this->attachTagsToTasks(array_values($tasks));
                    } catch (Throwable $exception) {
                        $this->logThrowable('Failed to get tasks', $exception);
                        return $this->createToolExecutionErrorResponse('Failed to get tasks', $id);
                    }
                    break;

                case 'create_task':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    if (!isset($arguments['title']) || !is_string($arguments['title']) || trim($arguments['title']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: title must be a non-empty string', $id);
                    }

                    $taskData = [
                        'project_id' => (int) $arguments['project_id'],
                        'title' => trim($arguments['title']),
                        'description' => $arguments['description'] ?? ''
                    ];
                    if (isset($arguments['column_id'])) {
                        $taskData['column_id'] = (int) $arguments['column_id'];
                    }
                    if (isset($arguments['swimlane_id']) && (int) $arguments['swimlane_id'] > 0) {
                        $taskData['swimlane_id'] = (int) $arguments['swimlane_id'];
                    }
                    $taskId = $this->container['taskCreationModel']->create($taskData);
                    $result = ['task_id' => $taskId];
                    break;

                case 'update_task':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }

                    $taskData = ['id' => $arguments['task_id']];
                    if (isset($arguments['title'])) $taskData['title'] = $arguments['title'];
                    if (isset($arguments['description'])) $taskData['description'] = $arguments['description'];
                    if (isset($arguments['column_id'])) $taskData['column_id'] = $arguments['column_id'];

                    $updateResult = $this->container['taskModificationModel']->update($taskData);
                    $result = ['success' => $updateResult];
                    break;

                case 'get_columns':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $columns = $this->container['columnModel']->getAll($arguments['project_id']);
                    $result = array_values($columns);
                    break;

                case 'move_task':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    $taskId = isset($arguments['task_id']) ? (int) $arguments['task_id'] : 0;
                    $columnId = isset($arguments['column_id']) ? (int) $arguments['column_id'] : 0;

                    if ($projectId <= 0 || $taskId <= 0 || $columnId <= 0) {
                        return $this->createToolExecutionErrorResponse(
                            'Invalid arguments: project_id, task_id, and column_id must be positive integers',
                            $id
                        );
                    }

                    $swimlaneId = isset($arguments['swimlane_id']) ? (int) $arguments['swimlane_id'] : 0;

                    $moveResult = $this->container['taskPositionModel']->movePosition(
                        $projectId,
                        $taskId,
                        $columnId,
                        1,
                        $swimlaneId
                    );
                    $result = ['success' => $moveResult];
                    break;

                case 'get_task_details':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }

                    if (!empty($arguments['verbose'])) {
                        $task = $this->container['taskFinderModel']->getDetails($arguments['task_id']);
                    } else {
                        $task = $this->container['taskFinderModel']->getById($arguments['task_id']);
                    }
                    $result = is_array($task) ? $this->attachTagsToTask($task) : $task;
                    break;

                case 'delete_task':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }

                    $deleteResult = $this->container['taskModel']->remove($arguments['task_id']);
                    $result = ['success' => $deleteResult];
                    break;

                case 'assign_task':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0 || !isset($arguments['user_id']) || (int) $arguments['user_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id and user_id must be positive integers', $id);
                    }

                    $assignResult = $this->container['taskModificationModel']->update([
                        'id' => $arguments['task_id'],
                        'owner_id' => $arguments['user_id']
                    ]);
                    $result = ['success' => $assignResult];
                    break;

                case 'set_task_due_date':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0 || !isset($arguments['due_date']) || !is_string($arguments['due_date']) || trim($arguments['due_date']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer and due_date must be a non-empty string', $id);
                    }

                    $dueDateResult = $this->container['taskModificationModel']->update([
                        'id' => $arguments['task_id'],
                        'date_due' => $arguments['due_date']
                    ]);
                    $result = ['success' => $dueDateResult];
                    break;

                case 'add_task_comment':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0 || !isset($arguments['comment']) || !is_string($arguments['comment']) || trim($arguments['comment']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer and comment must be a non-empty string', $id);
                    }

                    $commentId = $this->container['commentModel']->create([
                        'task_id' => $arguments['task_id'],
                        'comment' => $arguments['comment'],
                        'user_id' => isset($arguments['user_id']) ? (int) $arguments['user_id'] : 0
                    ]);
                    $result = ['comment_id' => $commentId];
                    break;

                case 'get_users':
                    $users = $this->container['userModel']->getAll();
                    $result = array_values($users);
                    break;

                case 'get_task_comments':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }

                    $comments = $this->container['commentModel']->getAll($arguments['task_id']);
                    $result = array_values($comments);
                    break;

                // Administrative Tools - Column Management
                case 'create_column':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0 || !isset($arguments['title']) || !is_string($arguments['title']) || trim($arguments['title']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer and title must be a non-empty string', $id);
                    }

                    $columnId = $this->container['columnModel']->create(
                        $arguments['project_id'],
                        trim($arguments['title']),
                        $arguments['task_limit'] ?? 0,
                        $arguments['description'] ?? ''
                    );
                    $result = ['column_id' => $columnId];
                    break;

                case 'update_column':
                    if (!isset($arguments['column_id']) || (int) $arguments['column_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: column_id must be a positive integer', $id);
                    }

                    $columnId = (int) $arguments['column_id'];
                    $column = $this->container['columnModel']->getById($columnId);
                    if (empty($column)) {
                        return $this->createToolExecutionErrorResponse('Column not found', $id);
                    }
                    $title = $arguments['title'] ?? $column['title'];
                    $taskLimit = isset($arguments['task_limit']) ? (int) $arguments['task_limit'] : (int) $column['task_limit'];
                    $description = $arguments['description'] ?? $column['description'];
                    $hideInDashboard = isset($arguments['hide_in_dashboard'])
                        ? (int) $arguments['hide_in_dashboard']
                        : (int) ($column['hide_in_dashboard'] ?? 0);
                    $updateResult = $this->container['columnModel']->update($columnId, $title, $taskLimit, $description, $hideInDashboard);
                    $result = ['success' => $updateResult];
                    break;

                case 'delete_column':
                    if (!isset($arguments['column_id']) || (int) $arguments['column_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: column_id must be a positive integer', $id);
                    }

                    $deleteResult = $this->container['columnModel']->remove($arguments['column_id']);
                    $result = ['success' => $deleteResult];
                    break;

                case 'reorder_columns':
                    return $this->handleReorderColumns($arguments, $id);

                // Administrative Tools - Category Management
                case 'create_category':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0 || !isset($arguments['name']) || !is_string($arguments['name']) || trim($arguments['name']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer and name must be a non-empty string', $id);
                    }

                    $categoryId = $this->container['categoryModel']->create([
                        'project_id' => $arguments['project_id'],
                        'name' => trim($arguments['name']),
                        'description' => $arguments['description'] ?? ''
                    ]);
                    $result = ['category_id' => $categoryId];
                    break;

                case 'update_category':
                    if (!isset($arguments['category_id']) || (int) $arguments['category_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: category_id must be a positive integer', $id);
                    }

                    $updateData = ['id' => $arguments['category_id']];
                    if (isset($arguments['name'])) $updateData['name'] = $arguments['name'];
                    if (isset($arguments['description'])) $updateData['description'] = $arguments['description'];
                    $updateResult = $this->container['categoryModel']->update($updateData);
                    $result = ['success' => $updateResult];
                    break;

                case 'delete_category':
                    if (!isset($arguments['category_id']) || (int) $arguments['category_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: category_id must be a positive integer', $id);
                    }

                    $deleteResult = $this->container['categoryModel']->remove($arguments['category_id']);
                    $result = ['success' => $deleteResult];
                    break;

                case 'get_categories':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $categories = $this->container['categoryModel']->getAll($arguments['project_id']);
                    $result = array_values($categories);
                    break;

                // Administrative Tools - Swimlane Management
                case 'create_swimlane':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0 || !isset($arguments['name']) || !is_string($arguments['name']) || trim($arguments['name']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer and name must be a non-empty string', $id);
                    }

                    $swimlaneId = $this->container['swimlaneModel']->create(
                        $arguments['project_id'],
                        trim($arguments['name']),
                        $arguments['description'] ?? ''
                    );
                    $result = ['swimlane_id' => $swimlaneId];
                    break;

                case 'update_swimlane':
                    if (!isset($arguments['swimlane_id']) || (int) $arguments['swimlane_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: swimlane_id must be a positive integer', $id);
                    }

                    $updateData = [];
                    if (isset($arguments['name'])) $updateData['name'] = $arguments['name'];
                    if (isset($arguments['description'])) $updateData['description'] = $arguments['description'];
                    $updateResult = $this->container['swimlaneModel']->update($arguments['swimlane_id'], $updateData);
                    $result = ['success' => $updateResult];
                    break;

                case 'delete_swimlane':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    $swimlaneId = isset($arguments['swimlane_id']) ? (int) $arguments['swimlane_id'] : 0;

                    if ($projectId <= 0 || $swimlaneId <= 0) {
                        return $this->createToolExecutionErrorResponse(
                            'Invalid arguments: project_id and swimlane_id must be positive integers',
                            $id
                        );
                    }

                    $deleteResult = $this->container['swimlaneModel']->remove($projectId, $swimlaneId);
                    $result = ['success' => $deleteResult];
                    break;

                case 'get_swimlanes':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $swimlanes = $this->container['swimlaneModel']->getAll($arguments['project_id']);
                    $result = array_values($swimlanes);
                    break;

                case 'get_project_actions':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $result = $this->getProjectActionsWithResolvedParams((int) $arguments['project_id']);
                    break;

                case 'get_available_actions':
                    $result = $this->container['actionManager']->getAvailableActions();
                    break;

                case 'get_compatible_action_events':
                    if (!isset($arguments['action_name']) || !is_string($arguments['action_name']) || trim($arguments['action_name']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: action_name must be a non-empty string', $id);
                    }

                    try {
                        $result = $this->container['actionManager']->getCompatibleEvents(trim($arguments['action_name']));
                    } catch (\RuntimeException $exception) {
                        return $this->createToolExecutionErrorResponse('Unknown action: ' . $arguments['action_name'], $id);
                    }
                    break;

                case 'create_action':
                    $result = ['action_id' => $this->createAutomaticAction($arguments)];
                    break;

                case 'remove_action':
                    if (!isset($arguments['action_id']) || (int) $arguments['action_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: action_id must be a positive integer', $id);
                    }

                    $result = ['success' => (bool) $this->container['actionModel']->remove((int) $arguments['action_id'])];
                    break;

                case 'get_project_tags':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $result = array_values($this->container['tagModel']->getAllByProject((int) $arguments['project_id']));
                    break;

                case 'get_colors':
                    $result = $this->container['colorModel']->getList();
                    break;

                case 'create_tag':
                    $result = ['tag_id' => $this->createProjectTag($arguments)];
                    break;

                case 'update_tag':
                    $result = ['success' => $this->updateProjectTag($arguments)];
                    break;

                case 'remove_tag':
                    if (!isset($arguments['tag_id']) || (int) $arguments['tag_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: tag_id must be a positive integer', $id);
                    }

                    $result = ['success' => (bool) $this->container['tagModel']->remove((int) $arguments['tag_id'])];
                    break;

                case 'set_task_tags':
                    $result = $this->setTaskTagsByName($arguments);
                    break;

                default:
                    return $this->errorResponse(-32602, 'Unknown tool: ' . $toolName, $id);
            }

            return $this->createSuccessResponse($result, $id);

        } catch (InvalidArgumentException $exception) {
            return $this->createToolExecutionErrorResponse($exception->getMessage(), $id);
        } catch (Throwable $exception) {
            $this->logThrowable(sprintf('Tool execution failed for %s', $toolName), $exception);
            return $this->createToolExecutionErrorResponse('Tool execution failed', $id);
        }
    }

    /**
     * Build the optional-duplication selection for duplicate_project.
     * Mirrors the checkbox defaults of the Clone project form:
     * permissions, custom roles, categories, tags, actions and custom
     * filters are copied unless disabled; metadata and tasks are not.
     */
    private function resolveDuplicationSelection(array $arguments): array
    {
        $optionalParts = [
            'copy_permissions' => 'projectPermissionModel',
            'copy_project_roles' => 'projectRoleModel',
            'copy_categories' => 'categoryModel',
            'copy_tags' => 'tagDuplicationModel',
            'copy_actions' => 'actionModel',
            'copy_custom_filters' => 'customFilterModel',
            'copy_metadata' => 'projectMetadataModel',
            'copy_tasks' => 'projectTaskDuplicationModel',
        ];

        $checkedByDefault = [
            'projectPermissionModel',
            'projectRoleModel',
            'categoryModel',
            'tagDuplicationModel',
            'actionModel',
            'customFilterModel',
        ];

        $selection = [];

        foreach ($optionalParts as $argument => $model) {
            $enabled = array_key_exists($argument, $arguments)
                ? (bool) $arguments[$argument]
                : in_array($model, $checkedByDefault, true);

            if ($enabled) {
                $selection[] = $model;
            }
        }

        return $selection;
    }

    /**
     * Duplicate a project (duplicate_project), wrapping ProjectDuplicationModel.
     * @return int|false
     */
    private function duplicateProject(array $arguments)
    {
        $name = null;
        if (isset($arguments['name']) && is_string($arguments['name']) && trim($arguments['name']) !== '') {
            $name = trim($arguments['name']);
        }

        $identifier = null;
        if (isset($arguments['identifier']) && is_string($arguments['identifier']) && trim($arguments['identifier']) !== '') {
            $identifier = trim($arguments['identifier']);
        }

        $ownerId = isset($arguments['owner_id']) ? max(0, (int) $arguments['owner_id']) : 0;
        $private = isset($arguments['is_private']) ? (bool) $arguments['is_private'] : null;

        return $this->container['projectDuplicationModel']->duplicate(
            (int) $arguments['project_id'],
            $this->resolveDuplicationSelection($arguments),
            $ownerId,
            $name,
            $private,
            $identifier
        );
    }

    /**
     * List a project's automatic actions (get_project_actions).
     * Mirrors ActionController::index: getList lookups and TextHelper::in
     * (missing keys become "?"). Raw params are kept; descriptions come from
     * actionManager / eventManager the same way the UI labels the rows.
     */
    private function getProjectActionsWithResolvedParams(int $projectId): array
    {
        $actions = $this->container['actionModel']->getAllByProject($projectId);

        if (!is_array($actions) || $actions === []) {
            return [];
        }

        $users = $this->container['projectUserRoleModel']->getAssignableUsersList($projectId);
        $projects = $this->container['projectModel']->getList(false, false);
        $lists = [
            'column_id' => $this->container['columnModel']->getList($projectId),
            'user_id' => $users,
            'owner_id' => $users,
            'project_id' => $projects,
            'color_id' => $this->container['colorModel']->getList(),
            'category_id' => $this->container['categoryModel']->getList($projectId),
            'link_id' => $this->container['linkModel']->getList(0, false),
            'swimlane_id' => $this->container['swimlaneModel']->getList($projectId),
        ];
        $availableActions = $this->container['actionManager']->getAvailableActions();
        $availableEvents = $this->container['eventManager']->getAll();

        foreach ($actions as &$action) {
            $params = is_array($action['params'] ?? null) ? $action['params'] : [];
            $action['params_resolved'] = $this->resolveActionParams($params, $lists);
            $actionName = $action['action_name'] ?? '';
            $eventName = $action['event_name'] ?? '';
            $action['action_description'] = $availableActions[$actionName] ?? $actionName;
            $action['event_description'] = $availableEvents[$eventName] ?? $eventName;
        }
        unset($action);

        return array_values($actions);
    }

    /**
     * Resolve action params the way action/index.php does: substring match on
     * the id suffix, then dictionary lookup with "?" for dangling keys.
     * Non-id params (duration, etc.) pass through as strings.
     *
     * @param array<string, mixed> $params
     * @param array<string, array<int|string, string>> $lists
     * @return array<string, string>
     */
    private function resolveActionParams(array $params, array $lists): array
    {
        $resolved = [];

        foreach ($params as $name => $value) {
            $name = (string) $name;
            $matched = false;

            foreach ($lists as $needle => $listing) {
                if (!str_contains($name, $needle)) {
                    continue;
                }

                $resolved[$name] = isset($listing[$value]) ? (string) $listing[$value] : '?';
                $matched = true;
                break;
            }

            if (!$matched) {
                $resolved[$name] = (string) $value;
            }
        }

        return $resolved;
    }

    /**
     * Create an automatic action, mirroring ActionProcedure::createAction
     * (available-action / compatible-event / required-params checks, then
     * ActionValidator, then ActionModel::create).
     */
    private function createAutomaticAction(array $arguments): int
    {
        if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
            throw new InvalidArgumentException('Invalid arguments: project_id must be a positive integer');
        }

        if (!isset($arguments['event_name']) || !is_string($arguments['event_name']) || trim($arguments['event_name']) === '') {
            throw new InvalidArgumentException('Invalid arguments: event_name must be a non-empty string');
        }

        if (!isset($arguments['action_name']) || !is_string($arguments['action_name']) || trim($arguments['action_name']) === '') {
            throw new InvalidArgumentException('Invalid arguments: action_name must be a non-empty string');
        }

        if (!isset($arguments['params']) || !is_array($arguments['params'])) {
            throw new InvalidArgumentException('Invalid arguments: params must be an object');
        }

        $values = [
            'project_id' => (int) $arguments['project_id'],
            'event_name' => trim($arguments['event_name']),
            'action_name' => trim($arguments['action_name']),
            'params' => $arguments['params'],
        ];

        list($valid) = $this->container['actionValidator']->validateCreation($values);
        if (!$valid) {
            throw new InvalidArgumentException('Invalid arguments: action creation validation failed');
        }

        $available = $this->container['actionManager']->getAvailableActions();
        if (!isset($available[$values['action_name']])) {
            throw new InvalidArgumentException('Unknown action: ' . $values['action_name']);
        }

        $action = $this->container['actionManager']->getAction($values['action_name']);
        if (!in_array($values['event_name'], $action->getEvents(), true)) {
            throw new InvalidArgumentException('Incompatible event for action: ' . $values['event_name']);
        }

        $required = $action->getActionRequiredParameters();
        foreach ($required as $param => $label) {
            if (!array_key_exists($param, $values['params'])) {
                throw new InvalidArgumentException('Missing action parameter: ' . $param);
            }
        }
        foreach ($values['params'] as $param => $value) {
            if (!array_key_exists($param, $required)) {
                throw new InvalidArgumentException('Unknown action parameter: ' . $param);
            }
        }

        $userId = isset($this->container['userSession'])
            ? (int) $this->container['userSession']->getId()
            : 0;

        if (!$this->container['actionValidator']->validateParameters($values['project_id'], $userId, $values['params'])) {
            throw new InvalidArgumentException('Action parameters not allowed for this project');
        }

        $actionId = $this->container['actionModel']->create($values);
        if ($actionId === false || (int) $actionId <= 0) {
            throw new InvalidArgumentException('Failed to create action');
        }

        return (int) $actionId;
    }

    private function createProjectTag(array $arguments): int
    {
        if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
            throw new InvalidArgumentException('Invalid arguments: project_id must be a positive integer');
        }

        if (!isset($arguments['name']) || !is_string($arguments['name']) || trim($arguments['name']) === '') {
            throw new InvalidArgumentException('Invalid arguments: name must be a non-empty string');
        }

        $colorId = null;
        if (isset($arguments['color_id']) && is_string($arguments['color_id']) && $arguments['color_id'] !== '') {
            $colorId = $arguments['color_id'];
        }

        $tagId = $this->container['tagModel']->create((int) $arguments['project_id'], trim($arguments['name']), $colorId);
        if ($tagId === false || (int) $tagId <= 0) {
            throw new InvalidArgumentException('Failed to create tag');
        }

        return (int) $tagId;
    }

    private function updateProjectTag(array $arguments): bool
    {
        if (!isset($arguments['tag_id']) || (int) $arguments['tag_id'] <= 0) {
            throw new InvalidArgumentException('Invalid arguments: tag_id must be a positive integer');
        }

        $tag = $this->container['tagModel']->getById((int) $arguments['tag_id']);
        if (!is_array($tag) || $tag === []) {
            throw new InvalidArgumentException('Tag not found');
        }

        $name = $tag['name'];
        if (isset($arguments['name'])) {
            if (!is_string($arguments['name']) || trim($arguments['name']) === '') {
                throw new InvalidArgumentException('Invalid arguments: name must be a non-empty string');
            }
            $name = trim($arguments['name']);
        }

        $colorId = $tag['color_id'] ?? null;
        if (array_key_exists('color_id', $arguments)) {
            $colorId = (is_string($arguments['color_id']) && $arguments['color_id'] !== '')
                ? $arguments['color_id']
                : null;
        }

        return (bool) $this->container['tagModel']->update((int) $arguments['tag_id'], $name, $colorId);
    }

    /**
     * @return array{success: bool, new_tags: list<array{id: int, name: string}>}
     */
    private function setTaskTagsByName(array $arguments): array
    {
        if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
            throw new InvalidArgumentException('Invalid arguments: task_id must be a positive integer');
        }

        if (!isset($arguments['tags']) || !is_array($arguments['tags'])) {
            throw new InvalidArgumentException('Invalid arguments: tags must be an array of strings');
        }

        $names = [];
        foreach ($arguments['tags'] as $index => $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                throw new InvalidArgumentException(sprintf('Invalid arguments: tags[%d] must be a non-empty string', $index));
            }
            $names[] = trim($tag);
        }

        $taskId = (int) $arguments['task_id'];
        $projectId = (int) $this->container['taskFinderModel']->getProjectId($taskId);
        if ($projectId <= 0) {
            throw new InvalidArgumentException('Task not found');
        }

        $newTags = [];
        foreach ($names as $name) {
            $existingId = (int) $this->container['tagModel']->getIdByName($projectId, $name);
            if ($existingId > 0) {
                continue;
            }

            $tagId = $this->container['tagModel']->create($projectId, $name);
            if ($tagId === false || (int) $tagId <= 0) {
                throw new InvalidArgumentException('Failed to create tag: ' . $name);
            }
            $newTags[] = ['id' => (int) $tagId, 'name' => $name];
        }

        $replace = !empty($arguments['replace']);
        $saved = $this->container['taskTagModel']->save($projectId, $taskId, $names, $replace);

        return ['success' => (bool) $saved, 'new_tags' => $newTags];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function attachTagsToTask(array $task): array
    {
        $task['tags'] = $this->normalizeTagRows(
            $this->container['taskTagModel']->getTagsByTask((int) ($task['id'] ?? 0))
        );

        return $task;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function attachTagsToTasks(array $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            $id = (int) ($task['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $grouped = $this->container['taskTagModel']->getTagsByTaskIds($ids);
        foreach ($tasks as $index => $task) {
            $tid = (int) ($task['id'] ?? 0);
            $tasks[$index]['tags'] = $this->normalizeTagRows($grouped[$tid] ?? []);
        }

        return $tasks;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, name: string, color_id: mixed}>
     */
    private function normalizeTagRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'color_id' => $row['color_id'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * List available resources
     */
    private function listResources(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'resources' => [
                    [
                        'uri' => 'kanboard://projects',
                        'name' => 'Project List',
                        'description' => 'List of all projects',
                        'mimeType' => 'application/json'
                    ],
                    [
                        'uri' => 'kanboard://users',
                        'name' => 'User List',
                        'description' => 'List of all users',
                        'mimeType' => 'application/json'
                    ]
                ]
            ]
        ];
    }

    /**
     * List available resource templates.
     */
    private function listResourceTemplates(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'resourceTemplates' => [],
            ],
        ];
    }

    /**
     * Read a resource
     */
    private function readResource(array $params, int|string|null $id): array
    {
        $uri = $params['uri'] ?? '';

        try {
            switch ($uri) {
                case 'kanboard://projects':
                    $projects = $this->container['projectModel']->getAll();
                    $content = json_encode(array_values($projects), JSON_PRETTY_PRINT);
                    break;

                case 'kanboard://users':
                    $users = $this->container['userModel']->getAll();
                    $content = json_encode(array_values($users), JSON_PRETTY_PRINT);
                    break;

                default:
                    return $this->errorResponse(-32002, 'Resource not found', $id, ['uri' => $uri]);
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => $content
                        ]
                    ]
                ]
            ];
        } catch (Throwable $exception) {
            $this->logThrowable('Resource read failed', $exception);
            return $this->errorResponse(-32603, 'Resource read failed', $id);
        }
    }

    /**
     * List offerings (Cursor-specific)
     */
    private function listOfferings(int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'offerings' => []
            ]
        ];
    }

    /**
     * Handle ping
     */
    private function ping(int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => (object)[]
        ];
    }

    /**
     * Get tools list for internal use
     */
    private function getToolsList(): array
    {
        // For internal use if needed
        return [];
    }

    /**
     * Get resources list for internal use
     */
    private function getResourcesList(): array
    {
        return [
            [
                'uri' => 'kanboard://projects',
                'name' => 'Project List',
                'description' => 'List of all projects',
                'mimeType' => 'application/json'
            ],
            [
                'uri' => 'kanboard://users',
                'name' => 'User List',
                'description' => 'List of all users',
                'mimeType' => 'application/json'
            ]
        ];
    }

    /**
     * Create error response
     */
    private function errorResponse(int $code, string $message, int|string|null $id, mixed $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }

    private function createToolExecutionErrorResponse(string $message, int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $message,
                    ],
                ],
                'isError' => true,
            ],
        ];
    }

    private function createSuccessResponse(mixed $result, int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT)
                    ]
                ]
            ]
        ];
    }

    /**
     * Canonical implementation of column reordering.
     * Demonstrates a clean control flow: sanitize -> verify -> execute.
     */
    private function handleReorderColumns(array $arguments, int|string|null $id): array
    {
        // 1. Sanitize and validate arguments
        try {
            [$projectId, $columnIds] = $this->sanitizeColumnReorderArguments($arguments);
        } catch (InvalidArgumentException $exception) {
            return $this->createAndLogFailure(
                'Invalid params for reorder_columns',
                $id,
                $exception->getMessage(),
                ['arguments' => $arguments]
            );
        }

        // 2. Verify state by comparing with existing columns
        /** @var ColumnModel $columnModel */
        $columnModel = $this->container['columnModel'];
        $currentIds = $this->getColumnIdsSnapshot($columnModel, $projectId);

        if (($mismatchError = $this->checkColumnMismatch($columnIds, $currentIds)) !== null) {
            return $this->createAndLogFailure(
                'State mismatch for reorder_columns',
                $id,
                $mismatchError,
                ['project_id' => $projectId, 'requested' => $columnIds, 'current' => $currentIds]
            );
        }

        // 3. Execute the reordering
        try {
            foreach ($columnIds as $index => $columnId) {
                $position = $index + 1;
                if ($columnModel->changePosition($projectId, $columnId, $position) !== true) {
                    // This is an exceptional case if the core model rejects the change
                    throw new \RuntimeException("Kanboard core rejected position change for column {$columnId} to {$position}.");
                }
            }
        } catch (Throwable $exception) {
            return $this->createAndLogFailure(
                'Execution failed for reorder_columns',
                $id,
                'Column reorder failed during execution.',
                ['project_id' => $projectId, 'requested' => $columnIds],
                $exception
            );
        }

        // Success
        return $this->createSuccessResponse(['success' => true], $id);
    }

    /**
     * Utility to check for mismatches between requested and current column sets.
     * Returns a formatted error string or null if there is no mismatch.
     */
    private function checkColumnMismatch(array $requested, array $current): ?string
    {
        if (count($requested) !== count($current)) {
            return 'Invalid params: number of columns does not match.';
        }

        $missing = array_values(array_diff($current, $requested));
        $unknown = array_values(array_diff($requested, $current));

        if (empty($missing) && empty($unknown)) {
            return null;
        }

        $details = [];
        if (!empty($missing)) {
            $details[] = 'missing ids [' . implode(', ', $missing) . ']';
        }
        if (!empty($unknown)) {
            $details[] = 'unknown ids [' . implode(', ', $unknown) . ']';
        }

        return 'Invalid params: column sets do not match (' . implode('; ', $details) . ').';
    }

    /**
     * Unified failure point for logging and creating an error response.
     */
    private function createAndLogFailure(
        string $logMessage,
        int|string|null $id,
        string $responseMessage,
        array $context,
        ?Throwable $exception = null
    ): array {
        $logContext = [
            'context' => $context,
            'mcp_channel' => 'reorder_columns_failure'
        ];

        if ($exception !== null) {
            $logContext['exception'] = $exception;
        }

        $this->logError($logMessage, $logContext);

        return $this->createToolExecutionErrorResponse($responseMessage, $id);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0:int,1:array<int,int>}
     */
    private function sanitizeColumnReorderArguments(array $arguments): array
    {
        if (!array_key_exists('project_id', $arguments)) {
            throw new InvalidArgumentException('Invalid params: project_id is required and must be a positive integer');
        }

        $projectId = $this->filterPositiveInteger($arguments['project_id']);

        if ($projectId === null) {
            throw new InvalidArgumentException('Invalid params: project_id must be a positive integer');
        }

        if (!array_key_exists('column_ids', $arguments)) {
            throw new InvalidArgumentException('Invalid params: column_ids must be a non-empty array of positive integers');
        }

        $columnIdArgument = $arguments['column_ids'];

        if (is_string($columnIdArgument)) {
            $columnIdArgument = array_values(array_filter(
                array_map('trim', explode(',', $columnIdArgument)),
                static fn(string $value): bool => $value !== ''
            ));
        } elseif ($columnIdArgument instanceof Traversable) {
            $columnIdArgument = iterator_to_array($columnIdArgument, false);
        }

        if (!is_array($columnIdArgument) || $columnIdArgument === []) {
            throw new InvalidArgumentException('Invalid params: column_ids must be a non-empty array of positive integers');
        }

        $columnIds = [];

        foreach (array_values($columnIdArgument) as $index => $rawColumnId) {
            $columnId = $this->filterPositiveInteger($rawColumnId);

            if ($columnId === null) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid params: column_ids[%d] must be a positive integer',
                    $index
                ));
            }

            $columnIds[] = $columnId;
        }

        if (count($columnIds) !== count(array_unique($columnIds))) {
            throw new InvalidArgumentException('Invalid params: column_ids must contain unique identifiers');
        }

        return [$projectId, $columnIds];
    }

    /**
     * @param ColumnModel|object $columnModel
     * @return array<int,int>
     */
    private function getColumnIdsSnapshot($columnModel, int $projectId): array
    {
        if (!is_object($columnModel) || !method_exists($columnModel, 'getAll')) {
            return [];
        }

        try {
            $columns = $columnModel->getAll($projectId);
        } catch (Throwable $exception) {
            $this->logThrowable(
                sprintf('Failed to fetch column snapshot for project %d', $projectId),
                $exception
            );

            return [];
        }

        if (!is_array($columns)) {
            return [];
        }

        $filtered = array_filter(
            $columns,
            static fn($column): bool => is_array($column) && isset($column['id']) && (int) $column['id'] > 0
        );

        return array_values(array_map(
            static fn(array $column): int => (int) $column['id'],
            $filtered
        ));
    }

    private function filterPositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)) {
            $filtered = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($filtered !== false) {
                return (int) $filtered;
            }

            return null;
        }

        return null;
    }

    private function logError(string $message, array $context = []): void
    {
        if (isset($this->container['logger'])) {
            $this->container['logger']->error($message, $context);
        }
    }

    /**
     * Log throwable details without exposing them to clients
     */
    private function logThrowable(string $message, Throwable $throwable): void
    {
        if (isset($this->container['logger'])) {
            $this->container['logger']->error($message, ['exception' => $throwable]);
        }
    }
}
