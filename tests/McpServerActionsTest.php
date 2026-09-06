<?php
declare(strict_types=1);

// RPC wiring tests for the automatic-action tools (KB#467).
// Mocks the Kanboard container models and drives McpServer::handleRequest;
// never touches a live Kanboard instance.
//
// Run: php tests/McpServerActionsTest.php

use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;

require __DIR__ . '/bootstrap.php';

// --- fakes -----------------------------------------------------------------

final class FakeActionModel
{
    public array $actionsByProject = [];
    public array $created = [];
    public int $nextId = 100;
    public array $removed = [];

    public function getAllByProject(int $projectId): array
    {
        return $this->actionsByProject[$projectId] ?? [];
    }

    public function create(array $values)
    {
        $id = $this->nextId++;
        $this->created[$id] = $values;
        return $id;
    }

    public function remove(int $actionId): bool
    {
        $this->removed[] = $actionId;
        return true;
    }
}

final class FakeListModel
{
    public array $list = [];

    public function getList(...$unused): array
    {
        return $this->list;
    }

    public function getAssignableUsersList(...$unused): array
    {
        return $this->list;
    }

    public function getAll(): array
    {
        return $this->list;
    }
}

final class FakeAutomaticAction
{
    public array $events = [];
    public array $required = [];

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getActionRequiredParameters(): array
    {
        return $this->required;
    }
}

final class FakeActionManager
{
    public array $available = [];
    public array $compatible = [];
    public array $instances = [];

    public function getAvailableActions(): array
    {
        return $this->available;
    }

    public function getCompatibleEvents(string $name): array
    {
        if (!array_key_exists($name, $this->available)) {
            throw new RuntimeException('Automatic Action Not Found: ' . $name);
        }

        return $this->compatible;
    }

    public function getAction(string $name): FakeAutomaticAction
    {
        if (!isset($this->instances[$name])) {
            throw new RuntimeException('Automatic Action Not Found: ' . $name);
        }

        return $this->instances[$name];
    }
}

final class FakeActionValidator
{
    public bool $creationValid = true;
    public bool $paramsValid = true;

    public function validateCreation(array $values): array
    {
        return [$this->creationValid, []];
    }

    public function validateParameters($projectId, $userId, array $params): bool
    {
        return $this->paramsValid;
    }
}

// --- harness ---------------------------------------------------------------

$checks = 0;
$failures = 0;

function check(bool $condition, string $label): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

function buildServer(
    FakeActionModel $actionModel,
    FakeListModel $columns,
    FakeListModel $swimlanes,
    FakeListModel $categories,
    FakeActionManager $actionManager,
    FakeListModel $users,
    FakeListModel $projects,
    FakeListModel $colors,
    FakeListModel $links,
    FakeListModel $events,
    FakeActionValidator $validator
): McpServer {
    return new McpServer(new ArrayObject([
        'actionModel' => $actionModel,
        'columnModel' => $columns,
        'swimlaneModel' => $swimlanes,
        'categoryModel' => $categories,
        'actionManager' => $actionManager,
        'projectUserRoleModel' => $users,
        'projectModel' => $projects,
        'colorModel' => $colors,
        'linkModel' => $links,
        'eventManager' => $events,
        'actionValidator' => $validator,
    ]));
}

function callTool(McpServer $server, string $name, array $arguments = []): array
{
    $response = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);

    if (!isset($response['result']['content'][0]['text']) || isset($response['error'])) {
        return ['isError' => true, 'text' => null, 'data' => null];
    }

    return [
        'isError' => $response['result']['isError'] ?? false,
        'text' => $response['result']['content'][0]['text'],
        'data' => json_decode($response['result']['content'][0]['text'], true),
    ];
}

// --- fixtures (mirrors ActionController::index lookups) --------------------

$actionName = '\\Kanboard\\Action\\TaskMoveColumnNotMovedPeriod';
$eventName = 'task.cronjob.daily';

$actionModel = new FakeActionModel();
$actionModel->actionsByProject[23] = [
    [
        'id' => 61,
        'project_id' => 23,
        'event_name' => $eventName,
        'action_name' => $actionName,
        'params' => ['duration' => '7', 'src_column_id' => '141', 'dest_column_id' => '133'],
    ],
    [
        'id' => 62,
        'project_id' => 23,
        'event_name' => $eventName,
        'action_name' => $actionName,
        'params' => [
            'duration' => '90',
            'src_column_id' => '777',
            'swimlane_id' => '19',
            'category_id' => '0',
            'color_id' => 'yellow',
            'user_id' => '1',
        ],
    ],
];

$columns = new FakeListModel();
$columns->list = [141 => 'Acceptance', 133 => 'Done'];

$swimlanes = new FakeListModel();
$swimlanes->list = [19 => 'Current'];

$categories = new FakeListModel();
$categories->list = [0 => 'No category', 4 => 'Bug'];

$users = new FakeListModel();
$users->list = [0 => 'Unassigned', 1 => 'admin'];

$projects = new FakeListModel();
$projects->list = [23 => 'template'];

$colors = new FakeListModel();
$colors->list = ['yellow' => 'Yellow'];

$links = new FakeListModel();

$events = new FakeListModel();
$events->list = [$eventName => 'Daily background job for tasks'];

$actionManager = new FakeActionManager();
$actionManager->available = [
    $actionName => 'Move the task to another column when not moved during a given period',
    '\\Kanboard\\Action\\TaskAssignSpecificUser' => 'Assign the task to a specific user',
];
$actionManager->compatible = [$eventName => 'Daily background job for tasks'];

$moveAction = new FakeAutomaticAction();
$moveAction->events = [$eventName];
$moveAction->required = [
    'duration' => 'Duration in days',
    'src_column_id' => 'Source column',
    'dest_column_id' => 'Destination column',
];
$actionManager->instances[$actionName] = $moveAction;

$validator = new FakeActionValidator();

// --- tests -----------------------------------------------------------------

$server = buildServer(
    $actionModel,
    $columns,
    $swimlanes,
    $categories,
    $actionManager,
    $users,
    $projects,
    $colors,
    $links,
    $events,
    $validator
);

$listResponse = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
    'params' => [],
]);
$toolNames = array_column($listResponse['result']['tools'] ?? [], 'name');
check(in_array('get_project_actions', $toolNames, true), 'tools/list exposes get_project_actions');
check(in_array('get_available_actions', $toolNames, true), 'tools/list exposes get_available_actions');
check(in_array('get_compatible_action_events', $toolNames, true), 'tools/list exposes get_compatible_action_events');
check(in_array('create_action', $toolNames, true), 'tools/list exposes create_action');
check(in_array('remove_action', $toolNames, true), 'tools/list exposes remove_action');

foreach ($listResponse['result']['tools'] as $tool) {
    if ($tool['name'] === 'get_project_actions') {
        check(($tool['inputSchema']['required'] ?? []) === ['project_id'], 'get_project_actions requires project_id');
    }
    if ($tool['name'] === 'get_available_actions') {
        check(empty($tool['inputSchema']['required'] ?? []), 'get_available_actions takes no arguments');
    }
    if ($tool['name'] === 'get_compatible_action_events') {
        check(($tool['inputSchema']['required'] ?? []) === ['action_name'], 'get_compatible_action_events requires action_name');
    }
}

$res = callTool($server, 'get_project_actions', ['project_id' => 23]);
check($res['isError'] === false, 'get_project_actions succeeds');
check($res['data'][0]['params'] === $actionModel->actionsByProject[23][0]['params'], 'raw params are preserved');
check(
    $res['data'][0]['params_resolved'] === ['duration' => '7', 'src_column_id' => 'Acceptance', 'dest_column_id' => 'Done'],
    'column ids resolve to titles via getList (UI parity)'
);
check(
    $res['data'][1]['params_resolved'] === [
        'duration' => '90',
        'src_column_id' => '?',
        'swimlane_id' => 'Current',
        'category_id' => 'No category',
        'color_id' => 'Yellow',
        'user_id' => 'admin',
    ],
    'dangling column is "?", category 0 is "No category", color/user/swimlane resolve'
);
check(
    $res['data'][0]['action_description'] === $actionManager->available[$actionName],
    'action_description comes from actionManager'
);
check(
    $res['data'][0]['event_description'] === $events->list[$eventName],
    'event_description comes from eventManager'
);

$res = callTool($server, 'get_project_actions', ['project_id' => 99]);
check($res['isError'] === false && $res['data'] === [], 'get_project_actions on project without actions returns []');

$res = callTool($server, 'get_project_actions', ['project_id' => -1]);
check($res['isError'] === true, 'get_project_actions rejects non-positive project_id');
$res = callTool($server, 'get_project_actions', []);
check($res['isError'] === true, 'get_project_actions rejects missing project_id');

$res = callTool($server, 'get_available_actions');
check($res['isError'] === false, 'get_available_actions succeeds');
check($res['data'] === $actionManager->available, 'get_available_actions returns class => description map');

$res = callTool($server, 'get_compatible_action_events', ['action_name' => '\\Kanboard\\Action\\TaskAssignSpecificUser']);
check($res['isError'] === false, 'get_compatible_action_events succeeds for known action');
check($res['data'] === [$eventName => 'Daily background job for tasks'], 'get_compatible_action_events returns event => description map');

$res = callTool($server, 'get_compatible_action_events', ['action_name' => 'Kanboard\\Action\\NotAnAction']);
check($res['isError'] === true, 'get_compatible_action_events rejects unknown action');
check((string) $res['text'] !== '' && str_contains((string) $res['text'], 'Unknown action'), 'unknown-action error mentions the cause');

$res = callTool($server, 'get_compatible_action_events', ['action_name' => '  ']);
check($res['isError'] === true, 'get_compatible_action_events rejects blank action_name');

$res = callTool($server, 'create_action', [
    'project_id' => 23,
    'event_name' => $eventName,
    'action_name' => $actionName,
    'params' => ['duration' => '7', 'src_column_id' => '141', 'dest_column_id' => '133'],
]);
check($res['isError'] === false && ($res['data']['action_id'] ?? null) === 100, 'create_action returns new action_id');
check(($actionModel->created[100]['params']['src_column_id'] ?? null) === '141', 'create_action persists params');

$res = callTool($server, 'create_action', [
    'project_id' => 23,
    'event_name' => $eventName,
    'action_name' => $actionName,
    'params' => ['duration' => '7'],
]);
check($res['isError'] === true && str_contains((string) $res['text'], 'Missing action parameter'), 'create_action rejects missing params');

$res = callTool($server, 'create_action', [
    'project_id' => 23,
    'event_name' => 'task.move.column',
    'action_name' => $actionName,
    'params' => ['duration' => '7', 'src_column_id' => '141', 'dest_column_id' => '133'],
]);
check($res['isError'] === true && str_contains((string) $res['text'], 'Incompatible event'), 'create_action rejects incompatible event');

$res = callTool($server, 'create_action', [
    'project_id' => 23,
    'event_name' => $eventName,
    'action_name' => '\\Kanboard\\Action\\NotAnAction',
    'params' => [],
]);
check($res['isError'] === true && str_contains((string) $res['text'], 'Unknown action'), 'create_action rejects unknown action');

$validator->paramsValid = false;
$res = callTool($server, 'create_action', [
    'project_id' => 23,
    'event_name' => $eventName,
    'action_name' => $actionName,
    'params' => ['duration' => '7', 'src_column_id' => '141', 'dest_column_id' => '133'],
]);
check($res['isError'] === true && str_contains((string) $res['text'], 'not allowed'), 'create_action rejects params that fail ActionValidator');
$validator->paramsValid = true;

$res = callTool($server, 'remove_action', ['action_id' => 61]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'remove_action succeeds');
check($actionModel->removed === [61], 'remove_action calls ActionModel::remove');

$res = callTool($server, 'remove_action', ['action_id' => 0]);
check($res['isError'] === true, 'remove_action rejects non-positive action_id');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
