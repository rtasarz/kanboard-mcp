<?php
declare(strict_types=1);

// A token bound to a Kanboard user must only see and change what that user's
// project roles allow. An unbound token (user id 0) keeps full access.
// Run: php tests/McpServerScopedTokenTest.php

use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;

require __DIR__ . '/bootstrap.php';

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

function callTool(McpServer $server, string $name, array $arguments = []): array
{
    $response = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);

    return [
        'isError' => $response['result']['isError'] ?? isset($response['error']),
        'text' => $response['result']['content'][0]['text'] ?? json_encode($response['error'] ?? null),
        'data' => json_decode($response['result']['content'][0]['text'] ?? 'null', true),
    ];
}

function denied(array $res): bool
{
    return $res['isError'] === true && str_contains((string) $res['text'], 'Access denied');
}

// --- fakes -----------------------------------------------------------------

final class FakeUserModel
{
    public array $users = [];

    public function getById($id)
    {
        return $this->users[(int) $id] ?? null;
    }
}

final class FakeProjectUserRoleModel
{
    /** @var array<int, array<int, string>> project => user => role */
    public array $roles = [];

    public function getUserRole($projectId, $userId): string
    {
        return $this->roles[(int) $projectId][(int) $userId] ?? '';
    }
}

final class FakeProjectPermissionModel
{
    public array $projectIdsByUser = [];

    public function getProjectIds($userId): array
    {
        return $this->projectIdsByUser[(int) $userId] ?? [];
    }
}

final class FakeProjectModel
{
    public array $projects = [];
    public ?array $lastCreate = null;

    public function getAll(): array
    {
        return $this->projects;
    }

    public function getAllByIds(array $ids): array
    {
        return array_values(array_intersect_key($this->projects, array_flip($ids)));
    }

    public function create(array $values, $userId = 0, $addUser = false): int
    {
        $this->lastCreate = [$values, $userId, $addUser];
        return 99;
    }
}

final class FakeTaskFinderModel
{
    public array $projectByTask = [];

    public function getProjectId($taskId): int
    {
        return $this->projectByTask[(int) $taskId] ?? 0;
    }

    public function getById($taskId)
    {
        $pid = $this->getProjectId($taskId);
        return $pid > 0 ? ['id' => (int) $taskId, 'project_id' => $pid, 'is_active' => 1] : null;
    }
}

final class FakeColumnModel
{
    public array $created = [];

    public function getAll($projectId): array
    {
        return [['id' => 10 * (int) $projectId, 'title' => 'Backlog', 'project_id' => (int) $projectId]];
    }

    public function create($projectId, $title, $limit = 0, $description = ''): int
    {
        $this->created[] = [(int) $projectId, $title];
        return 55;
    }

    public function getProjectId($columnId): int
    {
        return intdiv((int) $columnId, 10);
    }

    public function remove($columnId): bool
    {
        return true;
    }
}

final class FakeCommentModel
{
    public ?array $last = null;

    public function create(array $values): int
    {
        $this->last = $values;
        return 7;
    }
}

final class FakeTaskCreationModel
{
    public ?array $last = null;

    public function create(array $values): int
    {
        $this->last = $values;
        return 42;
    }
}

final class FakeTaskModel
{
    public function remove($taskId): bool
    {
        return true;
    }
}

// --- fixtures ---------------------------------------------------------------

$users = new FakeUserModel();
$users->users = [
    1 => ['id' => 1, 'username' => 'admin', 'role' => 'app-admin'],
    2 => ['id' => 2, 'username' => 'bob', 'role' => 'app-user'],
    3 => ['id' => 3, 'username' => 'mia', 'role' => 'app-manager'],
];

$roles = new FakeProjectUserRoleModel();
$roles->roles = [
    1 => [2 => 'project-member', 3 => 'project-manager'],
    2 => [2 => 'project-viewer'],
    3 => [2 => 'qa-custom-role'],
];

$perm = new FakeProjectPermissionModel();
$perm->projectIdsByUser = [2 => [1, 2, 3], 3 => [1]];

$projects = new FakeProjectModel();
$projects->projects = [
    1 => ['id' => 1, 'name' => 'alpha'],
    2 => ['id' => 2, 'name' => 'beta'],
    3 => ['id' => 3, 'name' => 'gamma'],
    4 => ['id' => 4, 'name' => 'secret'],
];

$finder = new FakeTaskFinderModel();
$finder->projectByTask = [11 => 1, 21 => 2, 41 => 4];

$columns = new FakeColumnModel();
$comments = new FakeCommentModel();
$taskCreation = new FakeTaskCreationModel();

$container = new ArrayObject([
    'userModel' => $users,
    'projectUserRoleModel' => $roles,
    'projectPermissionModel' => $perm,
    'projectModel' => $projects,
    'taskFinderModel' => $finder,
    'columnModel' => $columns,
    'commentModel' => $comments,
    'taskCreationModel' => $taskCreation,
    'taskModel' => new FakeTaskModel(),
]);

// --- unrestricted token (legacy behaviour) ----------------------------------

$server = new McpServer(new ArrayObject(['projectModel' => $projects, 'columnModel' => $columns, 'taskFinderModel' => $finder, 'taskModel' => new FakeTaskModel()]));
$res = callTool($server, 'get_projects');
check(count($res['data']) === 4, 'unrestricted token lists every project');
$res = callTool($server, 'create_column', ['project_id' => 4, 'title' => 'x']);
check($res['isError'] === false, 'unrestricted token can create a column without any permission model in the container');
$res = callTool($server, 'delete_task', ['task_id' => 41]);
check($res['isError'] === false, 'unrestricted token can delete a task in any project');

// --- bob: member on 1, viewer on 2, custom role on 3, nothing on 4 ----------

$bob = new McpServer($container, 2);
$res = callTool($bob, 'get_projects');
check(array_column($res['data'], 'id') === [1, 2, 3], 'bound user only lists their projects');

$resource = $bob->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'kanboard://projects']]);
check(array_column(json_decode($resource['result']['contents'][0]['text'], true), 'id') === [1, 2, 3], 'projects resource is filtered too');

check(callTool($bob, 'get_columns', ['project_id' => 1])['isError'] === false, 'member can read columns');
check(callTool($bob, 'get_columns', ['project_id' => 2])['isError'] === false, 'viewer can read columns');
check(denied(callTool($bob, 'get_columns', ['project_id' => 4])), 'no role: read denied');

$res = callTool($bob, 'create_task', ['project_id' => 1, 'title' => 't']);
check($res['isError'] === false && ($taskCreation->last['project_id'] ?? null) === 1, 'member can create a task');
check(denied(callTool($bob, 'create_task', ['project_id' => 2, 'title' => 't'])), 'viewer cannot create a task');
check(callTool($bob, 'create_task', ['project_id' => 3, 'title' => 't'])['isError'] === false, 'custom project role counts as member');
check(denied(callTool($bob, 'create_task', ['project_id' => 4, 'title' => 't'])), 'no role: create denied');

$columns->created = [];
check(denied(callTool($bob, 'create_column', ['project_id' => 1, 'title' => 'c'])), 'member cannot create a column (manager needed)');
check($columns->created === [], 'denied create_column never reached the model');
check(denied(callTool($bob, 'delete_column', ['column_id' => 10])), 'member cannot delete a column');

$res = callTool($bob, 'add_task_comment', ['task_id' => 11, 'comment' => 'hi', 'user_id' => 1]);
check($res['isError'] === false && ($comments->last['user_id'] ?? null) === 2, 'comment is attributed to the bound user, not to a client-supplied user_id');
check(denied(callTool($bob, 'add_task_comment', ['task_id' => 21, 'comment' => 'hi'])), 'viewer cannot comment');
check(denied(callTool($bob, 'delete_task', ['task_id' => 41])), 'task in a foreign project: denied via task lookup');
$res = callTool($bob, 'delete_task', ['task_id' => 999]);
check($res['isError'] === true && str_contains((string) $res['text'], 'not found'), 'unknown task reports not found');

$res = callTool($bob, 'create_project', ['name' => 'mine']);
check($res['isError'] === false && $projects->lastCreate !== null, 'app-user can create a project');
[$values, $ownerId, $addUser] = $projects->lastCreate;
check(($values['is_private'] ?? null) === 1 && $ownerId === 2 && $addUser === true, 'app-user project is private and owned by the bound user');

// --- mia: app-manager, manager on 1 ------------------------------------------

$mia = new McpServer($container, 3);
check(callTool($mia, 'create_column', ['project_id' => 1, 'title' => 'c'])['isError'] === false, 'project manager can create a column');
check(denied(callTool($mia, 'create_column', ['project_id' => 2, 'title' => 'c'])), 'manager of 1 is nobody on 2');
$projects->lastCreate = null;
callTool($mia, 'create_project', ['name' => 'team']);
check(!isset($projects->lastCreate[0]['is_private']), 'app-manager may create a non-private project');

// --- admin-bound token: Kanboard admins bypass project roles -----------------

$admin = new McpServer($container, 1);
check(count(callTool($admin, 'get_projects')['data']) === 4, 'admin-bound token lists every project');
check(callTool($admin, 'create_column', ['project_id' => 4, 'title' => 'c'])['isError'] === false, 'admin-bound token can manage any project');

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
