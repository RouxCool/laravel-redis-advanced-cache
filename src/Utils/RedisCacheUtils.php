<?php

namespace RedisAdvancedCache\Utils;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Rest\Controller as RestBaseController;
use Orion\Http\Controllers\Controller as OrionBaseController;

class RedisCacheUtils
{
    // Managed by RestAPI

    public static function isCacheableRestApi(Request $request): bool
    {
        return auth()->check() && !self::isWriteOperationRestAPI($request) && config('redis_advanced_cache.apis.rest');
    }

    private static function isWriteOperationRestAPI(Request $request): bool
    {
        $segments = $request->route()?->getName() ?? '';
        $parts = explode('.', $segments);
        $end = end($parts);

        return !in_array($end, ['search']);
    }

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

    // Managed by Orion

    public static function isCacheableOrion(Request $request): bool
    {
        return auth()->check() && !self::isWriteOperationOrion($request) && config('redis_advanced_cache.apis.orion');
    }

    private static function isWriteOperationOrion(Request $request): bool
    {
        $segments = $request->route()?->getName() ?? '';
        $parts = explode('.', $segments);
        $end = end($parts);

        return !in_array($end, ['index', 'search', 'show']);
    }

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

    // Regex read SQL

    public static function extractRelationsFromSQL($sql)
    {
        $joins = self::parseJoinsFromSql($sql);
        $relations = [];

        foreach ($joins as $join) {
            $table = $join['right_table'];

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

    // Regex read SQL Relations

    public static function parseJoinsFromSql($sql)
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

    // Get method SQL (INSERT, UPDATE, DELETE)

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

    // Get mainTable from SQL

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

    // Get mainTable from Request

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

    // Filter request

    public static function filterSQL($sql): ?string
    {
        $filters = [
            'update `users` set `last_authenticated_at` = ?, `users`.`updated_at` = ? where `id` = ?',
        ];

        return !in_array($sql, $filters);
    }
}
