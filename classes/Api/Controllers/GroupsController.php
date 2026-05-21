<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserGroupInterface;
use Grav\Common\Yaml;
use Grav\Framework\Flex\FlexDirectory;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\FlexBackend;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\GroupSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * User Groups CRUD.
 *
 * Groups are stored in `user/config/groups.yaml` as a keyed map. We prefer the
 * Flex `user-groups` directory when it's available (richer search/index), and
 * fall back to direct YAML I/O when Flex is disabled or the directory hasn't
 * been registered yet.
 *
 * All write operations require `admin.super` — matching the security@ gate on
 * the account blueprint's groups/access sections.
 */
class GroupsController extends AbstractApiController
{
    use FlexBackend;

    private ?GroupSerializer $serializer = null;

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.read');

        $directory = $this->getFlexDirectory('user-groups');
        if ($directory) {
            return $this->indexViaFlex($request, $directory);
        }
        return $this->indexViaYaml($request);
    }

    private function indexViaFlex(ServerRequestInterface $request, FlexDirectory $directory): ResponseInterface
    {
        $pagination = $this->getPagination($request);
        $query = $request->getQueryParams();
        $search = $query['search'] ?? null;

        $collection = $directory->getCollection();
        if ($search && $search !== '') {
            $collection = $collection->search((string) $search);
        }
        $collection = $collection->sort(['groupname' => 'asc']);

        $total = $collection->count();
        $slice = $collection->slice($pagination['offset'], $pagination['limit']);

        $data = [];
        foreach ($slice as $group) {
            if ($group instanceof UserGroupInterface) {
                $data[] = $this->getSerializer()->serialize($group);
            }
        }

        return ApiResponse::paginated(
            data: $data,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/groups',
        );
    }

    private function indexViaYaml(ServerRequestInterface $request): ResponseInterface
    {
        $pagination = $this->getPagination($request);
        $query = $request->getQueryParams();
        $search = strtolower((string) ($query['search'] ?? ''));

        $groups = $this->loadGroupsArray();

        $rows = [];
        foreach ($groups as $name => $entry) {
            if (!is_array($entry)) continue;
            $row = $this->getSerializer()->serializeArray((string) $name, $entry);
            if ($search !== '') {
                $haystack = strtolower(($row['groupname'] ?? '') . ' ' . ($row['readableName'] ?? '') . ' ' . ($row['description'] ?? ''));
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }
            $rows[] = $row;
        }

        usort($rows, static fn($a, $b) => strcasecmp($a['groupname'], $b['groupname']));

        $total = count($rows);
        $paged = array_slice($rows, $pagination['offset'], $pagination['limit']);

        return ApiResponse::paginated(
            data: $paged,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/groups',
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.read');

        $name = $this->getRouteParam($request, 'name');
        $data = $this->loadGroupRow($name);

        $etag = $this->generateEtag($data);
        return ApiResponse::create($data, 200, ['ETag' => '"' . $etag . '"']);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperOrAdmin($request);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['groupname']);

        $groupname = (string) $body['groupname'];
        if (!preg_match('/^[a-zA-Z0-9_-]{1,200}$/', $groupname)) {
            throw new ValidationException(
                'Invalid group name.',
                [['field' => 'groupname', 'message' => 'Group name must be 1-200 characters of letters, numbers, hyphens or underscores.']],
            );
        }

        $groups = $this->loadGroupsArray();
        if (isset($groups[$groupname])) {
            throw new ConflictException("Group '{$groupname}' already exists.");
        }

        $entry = $this->normalizeGroupPayload($body);
        $entry['groupname'] = $groupname;
        $groups[$groupname] = $entry;

        $this->saveGroupsArray($groups);

        $this->fireEvent('onApiGroupCreated', ['groupname' => $groupname, 'group' => $entry]);

        return ApiResponse::created(
            data: $this->getSerializer()->serializeArray($groupname, $entry),
            location: $this->getApiBaseUrl() . '/groups/' . $groupname,
            headers: $this->invalidationHeaders(['groups:create:' . $groupname, 'groups:list']),
        );
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperOrAdmin($request);

        $name = $this->getRouteParam($request, 'name');
        $groups = $this->loadGroupsArray();

        if (!isset($groups[$name])) {
            throw new NotFoundException("Group '{$name}' not found.");
        }

        $current = $this->getSerializer()->serializeArray((string) $name, $groups[$name]);
        $this->validateEtag($request, $this->generateEtag($current));

        $body = $this->getRequestBody($request);
        if (empty($body)) {
            throw new ValidationException('Request body must contain fields to update.');
        }

        $existing = $groups[$name];
        $merged = $existing;
        foreach (['readableName', 'description', 'icon'] as $field) {
            if (array_key_exists($field, $body)) {
                $merged[$field] = (string) $body[$field];
            }
        }
        if (array_key_exists('enabled', $body)) {
            $merged['enabled'] = (bool) $body['enabled'];
        }
        if (array_key_exists('access', $body)) {
            $merged['access'] = is_array($body['access']) ? $body['access'] : [];
        }
        // Renames are out of scope — groupname is the storage key.
        $merged['groupname'] = (string) $name;

        $groups[$name] = $merged;
        $this->saveGroupsArray($groups);

        $this->fireEvent('onApiGroupUpdated', ['groupname' => $name, 'group' => $merged]);

        $row = $this->getSerializer()->serializeArray((string) $name, $merged);
        return $this->respondWithEtag($row, 200, ['groups:update:' . $name, 'groups:list']);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperOrAdmin($request);

        $name = $this->getRouteParam($request, 'name');
        $groups = $this->loadGroupsArray();

        if (!isset($groups[$name])) {
            throw new NotFoundException("Group '{$name}' not found.");
        }

        unset($groups[$name]);
        $this->saveGroupsArray($groups);

        $this->fireEvent('onApiGroupDeleted', ['groupname' => $name]);

        return ApiResponse::noContent(
            $this->invalidationHeaders(['groups:delete:' . $name, 'groups:list']),
        );
    }

    private function loadGroupRow(?string $name): array
    {
        if ($name === null || $name === '') {
            throw new ValidationException('Group name is required.');
        }

        $directory = $this->getFlexDirectory('user-groups');
        if ($directory) {
            $group = $directory->getObject($name);
            if ($group instanceof UserGroupInterface) {
                return $this->getSerializer()->serialize($group);
            }
        }

        $groups = $this->loadGroupsArray();
        if (!isset($groups[$name]) || !is_array($groups[$name])) {
            throw new NotFoundException("Group '{$name}' not found.");
        }

        return $this->getSerializer()->serializeArray((string) $name, $groups[$name]);
    }

    /**
     * Load groups from in-memory config (which Grav populates from
     * user/config/groups.yaml on bootstrap, with env overlays applied).
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadGroupsArray(): array
    {
        $raw = $this->config->get('groups', []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Persist groups back to user/config/groups.yaml. Writes to the base
     * config file (not an env overlay) so saved groups are visible in every
     * environment — mirrors how classic admin's groups page writes.
     *
     * @param array<string, array<string, mixed>> $groups
     */
    private function saveGroupsArray(array $groups): void
    {
        $grav = Grav::instance();
        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $grav['locator'];

        $userConfig = $locator->findResource('user://config', true);
        if (!$userConfig) {
            throw new \RuntimeException('Base user/config directory not found.');
        }
        $filePath = $userConfig . '/groups.yaml';

        file_put_contents($filePath, Yaml::dump($groups, 99, 2));

        // Reflect in-memory so subsequent reads in the same request see it.
        $this->config->set('groups', $groups);

        // Clear the standard cache so the next request rebuilds the config
        // tree (and any Flex user-groups index cached against the file mtime).
        $grav['cache']->clearCache('standard');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function normalizeGroupPayload(array $body): array
    {
        $entry = [];
        foreach (['readableName', 'description', 'icon'] as $field) {
            if (isset($body[$field])) {
                $entry[$field] = (string) $body[$field];
            }
        }
        $entry['enabled'] = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true;
        $entry['access'] = isset($body['access']) && is_array($body['access']) ? $body['access'] : [];
        return $entry;
    }

    private function getSerializer(): GroupSerializer
    {
        return $this->serializer ??= new GroupSerializer();
    }

    /**
     * Groups are admin-level governance — match the security@: admin.super
     * gate that account.yaml places on the groups/access sections.
     */
    private function requireSuperOrAdmin(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);
        if ($this->isSuperAdmin($user)) {
            return;
        }
        // Fall through to permission check so the error response carries the
        // standard "missing permission" shape rather than a bare forbidden.
        $this->requirePermission($request, 'admin.super');
    }
}
