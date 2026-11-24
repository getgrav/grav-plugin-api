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
use RocketTheme\Toolbox\File\File;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * User Groups CRUD.
 *
 * Groups are stored in `user/config/groups.yaml` as a keyed map. We prefer the
 * Flex `user-groups` directory for reads when it's available (richer
 * search/index), and fall back to direct YAML I/O when Flex is disabled or the
 * directory hasn't been registered yet. Both read the same base file (it is the
 * Flex directory's SimpleStorage), and so do the writes, so a group that show()
 * finds is always one update()/delete() can act on.
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
            query: $request->getQueryParams(),
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
            query: $request->getQueryParams(),
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

        $this->saveGroupRow($groupname, $entry, true);

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

        $this->saveGroupRow((string) $name, $merged);

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

        $this->saveGroupRow((string) $name, null);

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
     * Load groups straight from the base user/config/groups.yaml: the file the
     * Flex `user-groups` directory stores to, and the one saveGroupRow()
     * writes. Not `$this->config->get('groups')`: that has env overlays merged
     * in, so a group from an overlay was found here but not by Flex, and saving
     * the merged array baked overlay-only groups into the base file.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadGroupsArray(): array
    {
        $file = $this->groupsFilePath();
        if ($file === null || !is_file($file)) {
            return [];
        }

        $raw = Yaml::parse((string) file_get_contents($file));
        return is_array($raw) ? $raw : [];
    }

    private function groupsFilePath(): ?string
    {
        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator $locator */
        $locator = $this->grav['locator'];

        // Resolved the way the Flex storage resolves its `user://config/groups.yaml`
        // (UniformResourceLocator::getResource()), so both name the file, and the
        // lock taken for it, with the same string.
        $file = $locator->findResource('user://config/groups.yaml', true)
            ?: $locator->findResource('user://config/groups.yaml', true, true);
        if (is_string($file) && $file !== '') {
            return $file;
        }

        $userConfig = $locator->findResource('user://config', true);

        return $userConfig ? $userConfig . '/groups.yaml' : null;
    }

    /**
     * Write one group (or remove it, with null) in user/config/groups.yaml.
     * Writes to the base config file (not an env overlay) so saved groups are
     * visible in every environment, as classic admin's groups page
     * writes.
     *
     * Holds the lock Flex's SimpleStorage takes to save the same file and reads
     * the file again under it, so a group saved meanwhile, here or through Flex,
     * is not undone by writing back the copy read at the start of the request.
     *
     * @param array<string, mixed>|null $entry
     * @param bool $create Refuse when the group exists by now.
     */
    private function saveGroupRow(string $name, ?array $entry, bool $create = false): void
    {
        $grav = Grav::instance();

        $filePath = $this->groupsFilePath();
        if ($filePath === null) {
            throw new \RuntimeException('Base user/config directory not found.');
        }

        // Same lock file as core's SimpleStorage::lockFile() (Grav 2.2.35+).
        $lock = File::instance(CACHE_DIR . 'compiled/files/' . md5($filePath) . '.lock');
        try {
            $lock->lock();
        } catch (\RuntimeException) {
            // Carry on without the lock if it cannot be created, as core does.
        }

        try {
            clearstatcache(true, $filePath);
            $groups = $this->loadGroupsArray();
            if ($create && isset($groups[$name])) {
                throw new ConflictException("Group '{$name}' already exists.");
            }
            if ($entry === null) {
                unset($groups[$name]);
            } else {
                $groups[$name] = $entry;
            }

            file_put_contents($filePath, Yaml::dump($groups, 99, 2));
        } finally {
            $lock->free();
        }

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
        // requireSuper() runs the API-key scope cap before the super short-circuit,
        // so a scoped key minted on a super account cannot write groups it wasn't
        // scoped for (GHSA-jqgq-v53x-x99g). Do NOT replace this with a bare
        // isSuperAdmin() early-return — that skips the cap.
        $this->requireSuper($request);
    }
}
