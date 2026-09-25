<?php

namespace App\Support\OpenApi;

use App\Http\Controllers\Api\V1\BaseCrudController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionMethod;

/**
 * OpenAPI 3.1 for /api/v1, built from the live routes and the validation rules
 * the controllers actually use (FormRequests, CRUD rules(), inline validate()),
 * so the published spec cannot drift from the code (plan A8 / Part D, P4-7).
 */
class Generator
{
    public function spec(): array
    {
        $paths = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') || str_contains($uri, '{fallbackPlaceholder}')) {
                continue;
            }
            $path = '/'.substr($uri, strlen('api/v1/'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, strtolower($method), $path);
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => config('app.name').' API', 'version' => '1.0.0',
                'description' => 'Shop, sync, team, billing and reports API. Every response uses the envelope {code, message, data, meta?, errors?}; refusals carry errors.code. Generated from the routes and their validation rules.'],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum token from /auth/login, /auth/register or /auth/otp/verify']],
                'schemas' => [
                    'Envelope' => ['type' => 'object', 'properties' => ['code' => ['type' => 'integer', 'enum' => [0, 1]], 'message' => ['type' => 'string'], 'data' => [], 'meta' => ['type' => 'object'], 'errors' => ['type' => 'object']]],
                    'Error' => ['type' => 'object', 'properties' => ['code' => ['type' => 'integer', 'enum' => [0]], 'message' => ['type' => 'string'],
                        'errors' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'description' => 'machine-readable reason, e.g. forbidden, plan_limit_reached, insufficient_stock']]]]],
                ],
                'parameters' => ['DeviceId' => ['name' => 'X-Device-Id', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Registered device id (sync and offline documents)']],
            ],
            'paths' => $paths,
        ];
    }

    private function operation(Route $route, string $method, string $path): array
    {
        $action = $route->getActionName();
        [$class, $fn] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
        $rules = $this->rules($class, $fn, $method);
        $tag = ucfirst(str_replace('-', ' ', explode('/', trim($path, '/'))[0] ?: 'root'));
        $op = [
            'tags' => [$tag],
            'summary' => $this->summary($class, $fn, $method, $path),
            'operationId' => $method.str_replace(['/', '{', '}', '-', '.'], ['_', '', '', '_', '_'], $path),
            'responses' => [
                $method === 'post' ? '201' : '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]],
                '401' => ['description' => 'Not signed in'],
                '403' => ['description' => 'Role or plan does not allow it', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                '422' => ['description' => 'Validation or business rule', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            ],
        ];
        $params = [];
        preg_match_all('/\{(\w+)\??\}/', $path, $m);
        foreach ($m[1] as $p) {
            $params[] = ['name' => $p, 'in' => 'path', 'required' => true, 'schema' => ['type' => in_array($p, ['id', 'userId'], true) ? 'integer' : 'string']];
        }
        if (in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            $op['security'] = [['bearer' => []]];
            $params[] = ['$ref' => '#/components/parameters/DeviceId'];
        }
        if ($rules !== []) {
            $schema = $this->schema($rules);
            if (in_array($method, ['get', 'delete'], true)) {
                foreach ($schema['properties'] as $name => $prop) {
                    $params[] = ['name' => $name, 'in' => 'query', 'required' => in_array($name, $schema['required'] ?? [], true), 'schema' => $prop];
                }
            } else {
                $op['requestBody'] = ['required' => ! empty($schema['required']), 'content' => ['application/json' => ['schema' => $schema]]];
            }
        }
        if ($params) {
            $op['parameters'] = $params;
        }

        return $op;
    }

    private function summary(string $class, string $fn, string $method, string $path): string
    {
        if ($class !== 'Closure' && class_exists($class) && method_exists($class, $fn)) {
            $doc = (string) (new ReflectionMethod($class, $fn))->getDocComment();
            foreach (preg_split('/\R/', $doc) ?: [] as $line) {
                $line = trim(preg_replace('#^\s*/?\*+/?#', '', $line));
                if ($line !== '' && ! str_starts_with($line, '@')) {
                    return mb_substr($line, 0, 150);
                }
            }
        }

        return strtoupper($method).' '.$path;
    }

    /** @return array<string, string> field => rule text */
    private function rules(string $class, string $fn, string $method): array
    {
        if ($class === 'Closure' || ! class_exists($class) || ! method_exists($class, $fn)) {
            return [];
        }
        $ref = new ReflectionMethod($class, $fn);
        foreach ($ref->getParameters() as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                $form = new ($type->getName())();

                return method_exists($form, 'rules') ? $this->flatten((array) $form->rules()) : [];
            }
        }
        $src = implode('', array_slice(file((string) $ref->getFileName()) ?: [], $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        if (is_subclass_of($class, BaseCrudController::class) && in_array($fn, ['store', 'update'], true) && ! str_contains($src, 'validate([')) {
            try {
                $request = Request::create('/', 'POST');
                $request->setUserResolver(fn () => (object) ['company_id' => 0, 'id' => 0]);
                $rulesRef = new ReflectionMethod($class, 'rules');
                $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

                $crud = $this->flatten($rulesRef->invoke($controller, $request, null));
                if ($crud !== []) {
                    return $crud;
                }
            } catch (\Throwable) {
                // fall through to the source
            }
        }
        if (! str_contains($src, 'validate([') && preg_match_all('/\$this->(\w+)\(/', $src, $calls)) {
            foreach (array_unique($calls[1]) as $helper) {
                if (method_exists($class, $helper)) {
                    $h = new ReflectionMethod($class, $helper);
                    $hs = implode('', array_slice(file((string) $h->getFileName()) ?: [], $h->getStartLine() - 1, $h->getEndLine() - $h->getStartLine() + 1));
                    if (str_contains($hs, 'validate([')) {
                        return $this->parseValidate($hs);
                    }
                }
            }
        }

        return $this->parseValidate($src);
    }

    /** Rules as given to validate(): arrays of strings/objects → one readable string per field. */
    private function flatten(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $rule) {
            $parts = is_array($rule) ? $rule : explode('|', (string) $rule);
            $out[$field] = implode('|', array_map(function ($r) {
                if (is_string($r)) {
                    return $r;
                }
                if ($r instanceof \Illuminate\Validation\Rules\In) {
                    return (string) $r;
                }
                if ($r instanceof \Illuminate\Validation\Rules\Exists) {
                    return 'integer';
                }

                return is_object($r) ? class_basename($r) : '';
            }, $parts));
        }

        return $out;
    }

    /** Pull `'field' => [...]` / `'field' => '...'` pairs out of the first validate([...]) call in the method. */
    private function parseValidate(string $src): array
    {
        $at = strpos($src, 'validate([');
        if ($at === false) {
            return [];
        }
        $start = $at + strlen('validate(');
        $depth = 0;
        $end = $start;
        for ($i = $start; $i < strlen($src); $i++) {
            $depth += $src[$i] === '[' ? 1 : ($src[$i] === ']' ? -1 : 0);
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
        $body = substr($src, $start + 1, $end - $start - 1);
        $out = [];
        if (preg_match_all("/'([\\w.*]+)'\\s*=>\\s*(\\[(?:[^\\[\\]]|\\[(?:[^\\[\\]]|\\[[^\\[\\]]*\\])*\\])*\\]|'[^']*')/", $body, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $field, $rule]) {
                $rule = str_contains($rule, 'Rule::exists') ? $rule.'|integer' : $rule;
                if (preg_match('/Rule::in\\(\\[([^\\]]*)\\]\\)/', $rule, $in)) {
                    $rule .= '|in:'.implode(',', array_map(fn ($v) => trim($v, " '\""), explode(',', $in[1])));
                }
                $out[$field] = str_replace([', ', ','."\n"], '|', str_replace(["'", '[', ']'], '', $rule)); // one rule per |, like 'a|b' strings
            }
        }

        return $out;
    }

    private function schema(array $rules): array
    {
        $root = ['type' => 'object', 'properties' => [], 'required' => []];
        foreach ($rules as $field => $rule) {
            $segments = explode('.', $field);
            $node = &$root;
            foreach ($segments as $i => $seg) {
                $last = $i === count($segments) - 1;
                if ($seg === '*') {
                    $node['items'] ??= ['type' => 'object', 'properties' => [], 'required' => []];
                    if ($last) {
                        $node['items'] = $this->type($rule);
                    }
                    $node = &$node['items'];

                    continue;
                }
                $node['properties'] ??= [];
                if ($last) {
                    $node['properties'][$seg] = array_merge($node['properties'][$seg] ?? [], $this->type($rule));
                    if (preg_match('/(^|[|,\s])required([|,\s]|$)/', $rule)) {
                        $node['required'][] = $seg;
                    }
                } else {
                    $node['properties'][$seg] ??= ['type' => 'array'];
                    $node = &$node['properties'][$seg];
                }
            }
            unset($node);
        }
        $clean = function (array $s) use (&$clean) {
            if (isset($s['required']) && $s['required'] === []) {
                unset($s['required']);
            }
            foreach (['properties'] as $k) {
                if (isset($s[$k])) {
                    $s[$k] = array_map($clean, $s[$k]);
                }
            }
            if (isset($s['items']) && is_array($s['items'])) {
                $s['items'] = $clean($s['items']);
            }

            return $s;
        };

        return $clean($root);
    }

    private function type(string $rule): array
    {
        $t = match (true) {
            (bool) preg_match('/\b(integer)\b/', $rule) => ['type' => 'integer'],
            (bool) preg_match('/\b(numeric)\b/', $rule) => ['type' => 'number'],
            (bool) preg_match('/\bboolean\b/', $rule) => ['type' => 'boolean'],
            (bool) preg_match('/\barray\b/', $rule) => ['type' => 'array'],
            (bool) preg_match('/\bfile\b|\bimage\b/', $rule) => ['type' => 'string', 'format' => 'binary'],
            default => ['type' => 'string'],
        };
        if (preg_match('/\bdate\b/', $rule)) {
            $t['format'] = 'date';
        } elseif (preg_match('/\bemail\b/', $rule)) {
            $t['format'] = 'email';
        } elseif (preg_match('/\buuid\b/', $rule)) {
            $t['format'] = 'uuid';
        }
        if (preg_match('/(?:^|\|)\s*in:([^|]+)/', $rule, $m)) {
            $vals = array_values(array_filter(array_map('trim', explode(',', str_replace('"', '', $m[1]))), fn ($v) => $v !== ''));
            if ($vals) {
                $t['enum'] = $vals;
            }
        }
        if (preg_match('/max:(\d+)/', $rule, $m) && $t['type'] === 'string') {
            $t['maxLength'] = (int) $m[1];
        }
        if (str_contains($rule, 'nullable')) {
            $t['type'] = [$t['type'], 'null'];
        }

        return $t;
    }

    /** Postman v2.1 collection from the spec (plan A8 "Postman collection in repo"). */
    public function postman(array $spec): array
    {
        $folders = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                $url = '{{base_url}}'.preg_replace('/\{(\w+)\??\}/', ':$1', $path);
                $item = ['name' => $op['summary'], 'request' => [
                    'method' => strtoupper($method),
                    'header' => array_values(array_filter([['key' => 'Accept', 'value' => 'application/json'], isset($op['security']) ? ['key' => 'Authorization', 'value' => 'Bearer {{token}}'] : null])),
                    'url' => ['raw' => $url, 'host' => ['{{base_url}}'], 'path' => array_values(array_filter(explode('/', preg_replace('/\{(\w+)\??\}/', ':$1', $path))))],
                ]];
                $body = $op['requestBody']['content']['application/json']['schema'] ?? null;
                if ($body) {
                    $item['request']['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
                    $item['request']['body'] = ['mode' => 'raw', 'raw' => json_encode($this->example($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
                }
                $folders[$op['tags'][0]][] = $item;
            }
        }
        ksort($folders);

        return [
            'info' => ['name' => $spec['info']['title'], 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json', 'description' => $spec['info']['description']],
            'variable' => [['key' => 'base_url', 'value' => $spec['servers'][0]['url']], ['key' => 'token', 'value' => '']],
            'item' => array_map(fn ($name, $items) => ['name' => $name, 'item' => $items], array_keys($folders), $folders),
        ];
    }

    private function example(array $schema): mixed
    {
        $type = is_array($schema['type'] ?? null) ? $schema['type'][0] : ($schema['type'] ?? 'string');

        return match ($type) {
            'object' => (object) array_map(fn ($p) => $this->example($p), $schema['properties'] ?? []),
            'array' => isset($schema['items']) ? [$this->example($schema['items'])] : [],
            'integer' => 1,
            'number' => 1000,
            'boolean' => true,
            default => $schema['enum'][0] ?? (($schema['format'] ?? '') === 'date' ? now()->toDateString() : ''),
        };
    }
}
