<?php

namespace RedisAdvancedCache\Utils;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Rest\Controller as RestBaseController;
use Orion\Http\Controllers\Controller as OrionBaseController;

class RedisCacheUtils
{
    // ================================
    // REST API Cache Checks
    // ================================

    /**
     * Determine if a REST API request is cacheable.
     *
     * @param Request $request
     * @return bool
     */
    public static function isCacheableRestApi(Request $request): bool
    {
        return auth()->check() 
            && !self::isWriteOperationRestAPI($request) 
            && config('redis_advanced_cache.apis.rest');
    }

    /**
     * Check if a REST API request is a write operation.
     * Only 'search' is considered a read operation here.
     *
     * @param Request $request
     * @return bool
     */
    private static function isWriteOperationRestAPI(Request $request): bool
    {
        $segments = $request->route()?->getName() ?? '';
        $parts = explode('.', $segments);
        $end = end($parts);

        return !in_array($end, ['search']);
    }

    /**
     * Check if the current route is managed by a REST controller.
     *
     * @param Request $request
     * @return bool
     */
    public static function isRouteManagedByRestApi(Request $request): bool
    {
        if (!class_exists(RestBaseController::class)) {
            return false;
        }

        $route = $request->route();
        if (!$route) {
            return false;
        }

        $controller = $route->getController();
        return $controller instanceof RestBaseController;
    }

    // ================================
    // Orion API Cache Checks
    // ================================

    /**
     * Determine if an Orion API request is cacheable.
     *
     * @param Request $request
     * @return bool
     */
    public static function isCacheableOrion(Request $request): bool
    {
        return auth()->check() 
            && !self::isWriteOperationOrion($request) 
            && config('redis_advanced_cache.apis.orion');
    }

    /**
     * Check if an Orion API request is a write operation.
     * 'index', 'search', and 'show' are considered read operations.
     *
     * @param Request $request
     * @return bool
     */
    private static function isWriteOperationOrion(Request $request): bool
    {
        $segments = $request->route()?->getName() ?? '';
        $parts = explode('.', $segments);
        $end = end($parts);

        return !in_array($end, ['index', 'search', 'show']);
    }

    /**
     * Check if the current route is managed by an Orion controller.
     *
     * @param Request $request
     * @return bool
     */
    public static function isRouteManagedByOrion(Request $request): bool
    {
        if (!class_exists(OrionBaseController::class)) {
            return false;
        }

        $route = $request->route();
        if (!$route) {
            return false;
        }

        $controller = $route->getController();
        return $controller instanceof OrionBaseController;
    }

    // ================================
    // SQL Analysis for Relations and Tables
    // ================================

    /**
     * Extract related tables from a SQL query by analyzing JOIN clauses.
     *
     * @param string $sql
     * @return array
     */
    public static function extractRelationsFromSQL($sql): array
    {
        $joins = self::parseJoinsFromSql($sql);
        $relations = [];

        foreach ($joins as $join) {
            $table = $join['right_table'];

            // Skip system/technical tables
            if (preg_match('/_user$|^pivot|^model_has|^article_viewer|^media$/', $table)) {
                continue;
            }

            $tableParts = explode('.', $table);
            $relation = end($tableParts);

            if (!in_array($relation, $relations)) {
                $relations[] = $relation;
            }
        }

        return $relations;
    }

    /**
     * Parse JOIN clauses from a SQL query.
     *
     * @param string $sql
     * @return array
     */
    public static function parseJoinsFromSql($sql): array
    {
        $pattern = '/(JOIN|LEFT\s+JOIN|RIGHT\s+JOIN|INNER\s+JOIN|OUTER\s+JOIN)\s+([`"\[\]\w.-]+)\s+ON\s+([`"\[\]\w.-]+)\s*=\s*([`"\[\]\w.-]+)/ix';

        preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);

        $results = [];
        foreach ($matches as $match) {
            $results[] = [
                'operation' => self::detectWriteOperation($sql),
                'type' => strtoupper(trim($match[1])),
                'right_table' => trim($match[2], '`[]"'),
                'on_left' => trim($match[3], '`[]"'),
                'on_right' => trim($match[4], '`[]"'),
                'left_table' => explode('.', trim($match[3], '`[]"'))[0],
            ];
        }

        return $results;
    }

    /**
     * Detect if a SQL query is a write operation (INSERT, UPDATE, DELETE).
     *
     * @param string $sql
     * @return string|null
     */
    public static function detectWriteOperation(string $sql): ?string
    {
        $sql = ltrim($sql);

        if (stripos($sql, 'insert') === 0) {
            return 'INSERT';
        }

        if (stripos($sql, 'update') === 0) {
            return 'UPDATE';
        }

        if (stripos($sql, 'delete') === 0) {
            return 'DELETE';
        }

        return null;
    }

    /**
     * Extract the main table from a SQL query.
     *
     * @param string $sql
     * @return string|null
     */
    public static function getMainTable(string $sql): ?string
    {
        $sql = ltrim(strtolower($sql));

        if (preg_match('/^insert\s+into\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^update\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^delete\s+from\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Resolve the main table name from a Request based on its controller.
     *
     * @param Request $request
     * @return string|null
     */
    public static function resolveMainTable(Request $request): ?string
    {
        $action = $request->route()?->getActionName();

        if (!$action || !str_contains($action, '@')) {
            return null;
        }

        [$controller] = explode('@', $action);
        $controllerBaseName = class_basename($controller);
        $modelNameBase = Str::replaceLast('Controller', '', $controllerBaseName);

        $candidates = [
            Str::plural($modelNameBase),
            Str::singular($modelNameBase),
        ];

        foreach ($candidates as $modelName) {
            $modelClass = 'App\\Models\\' . $modelName;
            if (class_exists($modelClass)) {
                $model = new $modelClass();
                if (method_exists($model, 'getTable')) {
                    return $model->getTable();
                }
            }
        }

        return null;
    }

    /**
     * Filter out specific SQL queries that should not be cached.
     *
     * @param string $sql
     * @return bool
     */
    public static function filterSQL($sql): bool
    {
        $filters = [
            'update `users` set `last_authenticated_at` = ?, `users`.`updated_at` = ? where `id` = ?',
        ];

        return !in_array($sql, $filters);
    }
}
