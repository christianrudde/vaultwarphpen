<?php

namespace App\Parity\Extractors;

use App\Parity\RustSource;
use RuntimeException;

/**
 * Collects every HTTP route Rocket mounts, following src/main.rs through the
 * routes() functions down to the handler attributes.
 */
final class RouteExtractor
{
    /**
     * Handler parameters that are neither request data nor access guards.
     */
    private const array IGNORED_PARAMETER_TYPES = ['DbConn', 'Notify', 'AnonymousNotify'];

    /** @var list<string> */
    public private(set) array $warnings = [];

    /** @var array<string, array<string, array{method: string, path: string, options: string, line: int, parameters: list<array{name: string, type: string}>}>> */
    private array $attributes = [];

    public function __construct(
        private readonly RustSource $source,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function extract(): array
    {
        $items = [];
        $ids = [];
        $mounted = [];

        foreach ($this->mounts() as $mount) {
            foreach ($this->handlers($mount['module'], $mount['function']) as [$file, $module, $handler]) {
                $route = $this->attributesIn($file)[$handler]
                    ?? throw new RuntimeException("No route attribute for {$handler} in {$file}.");
                $mounted["{$file}::{$handler}"] = true;

                [$path, $query] = array_pad(explode('?', $route['path'], 2), 2, '');
                $fullPath = $this->join($mount['base'], $path);
                $placeholders = $this->placeholders($path);
                $queryPlaceholders = $this->placeholders($query);
                $body = preg_match('/data\s*=\s*"<(\w+)>"/', $route['options'], $data) ? $data[1] : null;

                $guards = [];
                $bodyType = null;
                foreach ($route['parameters'] as $parameter) {
                    if ($parameter['name'] === $body) {
                        $bodyType = $parameter['type'];
                    } elseif (! in_array($parameter['name'], [...$placeholders, ...$queryPlaceholders], true)
                        && ! in_array(preg_replace('/<.*$/s', '', $parameter['type']), self::IGNORED_PARAMETER_TYPES, true)) {
                        $guards[] = $parameter['type'];
                    }
                }

                $id = "route:{$route['method']} {$this->normalize($fullPath)}";
                if (isset($ids[$id])) {
                    $id .= "#{$handler}";
                }
                $ids[$id] = true;

                $items[] = array_filter([
                    'id' => $id,
                    'kind' => 'route',
                    'method' => $route['method'],
                    'path' => $this->normalize($fullPath),
                    'query' => $queryPlaceholders,
                    'handler' => implode('::', [...array_slice($module, 1), $handler]),
                    'source' => "{$file}:{$route['line']}",
                    'guards' => $guards,
                    'body' => $bodyType,
                    'options' => trim((string) preg_replace('/,?\s*data\s*=\s*"<\w+>"\s*/', '', $route['options']), ' ,'),
                ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
            }
        }

        foreach ($this->source->files('src/api') as $file) {
            foreach ($this->attributesIn($file) as $handler => $route) {
                if (! isset($mounted["{$file}::{$handler}"])) {
                    $this->warnings[] = "Route {$route['method']} {$route['path']} ({$file}:{$route['line']}) is not mounted.";
                }
            }
        }

        return $items;
    }

    /**
     * The route functions mounted in main.rs, with their base path.
     *
     * @return list<array{base: string, module: list<string>, function: string}>
     */
    private function mounts(): array
    {
        $main = $this->source->read('src/main.rs');
        preg_match_all('/\.mount\(\[basepath,\s*"([^"]*)"\]\.concat\(\),\s*api::(\w+)\(\)\)/', $main, $matches, PREG_SET_ORDER);

        $api = $this->source->read('src/api/mod.rs');
        $start = strpos($api, 'pub use crate::api::{');
        if ($start === false) {
            throw new RuntimeException('Cannot find the route exports in src/api/mod.rs.');
        }
        $exports = $this->flattenUseTree(RustSource::block($api, $start + strlen('pub use crate::api::')));

        $mounts = [];
        foreach ($matches as [, $base, $alias]) {
            $path = $exports[$alias] ?? throw new RuntimeException("Unknown route export api::{$alias}.");
            $function = (string) array_pop($path);
            $mounts[] = ['base' => $base, 'module' => ['api', ...$path], 'function' => $function];
        }

        if ($mounts === []) {
            throw new RuntimeException('No route mounts found in src/main.rs.');
        }

        return $mounts;
    }

    /**
     * The handlers a routes() function returns, in source order.
     *
     * @param  list<string>  $module
     * @return list<array{string, list<string>, string}> File, module and handler name
     */
    private function handlers(array $module, string $function): array
    {
        $file = $this->source->moduleFile($module) ?? throw new RuntimeException('Module '.implode('::', $module).' not found.');
        $content = $this->source->read($file);

        if (! preg_match('/fn\s+'.preg_quote($function, '/').'\s*\(\)\s*->\s*Vec<(?:rocket::)?Route>\s*\{/', $content, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("No route function {$function}() in {$file}.");
        }
        $body = RustSource::block($content, $match[0][1] + strlen($match[0][0]) - 1);

        preg_match_all('/routes!\[(?<list>.*?)\]|\b(?<module>(?:\w+::)+)(?<function>\w+)\(\)/s', $body, $parts, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $handlers = [];
        foreach ($parts as $part) {
            if ($part['list'] !== null) {
                foreach (RustSource::splitTopLevel($part['list']) as $name) {
                    $segments = explode('::', $name);
                    $handler = (string) array_pop($segments);
                    $handlerModule = [...$module, ...$segments];
                    $handlerFile = $this->source->moduleFile($handlerModule) ?? $file;
                    $handlers[] = [$handlerFile, $handlerModule, $handler];
                }

                continue;
            }

            // Calls like accounts::routes(); anything that is no module (Vec::new()) is skipped.
            $submodule = [...$module, ...explode('::', rtrim((string) $part['module'], ':'))];
            if ($this->source->moduleFile($submodule) !== null) {
                array_push($handlers, ...$this->handlers($submodule, (string) $part['function']));
            }
        }

        return $handlers;
    }

    /**
     * Route attributes of the handlers in a file, keyed by handler name.
     *
     * @return array<string, array{method: string, path: string, options: string, line: int, parameters: list<array{name: string, type: string}>}>
     */
    private function attributesIn(string $file): array
    {
        if (isset($this->attributes[$file])) {
            return $this->attributes[$file];
        }

        $content = $this->source->read($file);
        preg_match_all(
            '/#\[(get|post|put|delete|patch|head|options)\(\s*"([^"]*)"\s*(?:,([^\]]*))?\)\]\s*(?:(?:#\[[^\n]*\]|\/\/[^\n]*)\s*)*(?:pub(?:\([^)]*\))?\s+)?(?:async\s+)?fn\s+(\w+)/',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $routes = [];
        foreach ($matches as $match) {
            $end = $match[0][1] + strlen($match[0][0]);
            $open = strpos($content, '(', $end);

            $routes[$match[4][0]] = [
                'method' => strtoupper($match[1][0]),
                'path' => $match[2][0],
                'options' => trim($match[3][0]),
                'line' => RustSource::lineAt($content, $match[0][1]),
                'parameters' => $open === false ? [] : $this->parameters(RustSource::block($content, $open)),
            ];
        }

        return $this->attributes[$file] = $routes;
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private function parameters(string $signature): array
    {
        $parameters = [];

        foreach (RustSource::splitTopLevel($signature) as $parameter) {
            if (preg_match('/^(?:mut\s+)?(\w+)\s*:\s*(.+)$/s', $parameter, $match)) {
                $parameters[] = ['name' => $match[1], 'type' => (string) preg_replace('/\s+/', ' ', $match[2])];
            }
        }

        return $parameters;
    }

    /**
     * Flatten a Rust use tree like `{a::b as c, d::{e, f as g}}` into alias => path.
     *
     * @param  list<string>  $prefix
     * @return array<string, list<string>>
     */
    private function flattenUseTree(string $tree, array $prefix = []): array
    {
        $exports = [];

        foreach (RustSource::splitTopLevel($tree) as $part) {
            if (preg_match('/^((?:\w+::)*)\{(.*)\}$/s', $part, $match)) {
                $nested = $match[1] === '' ? [] : explode('::', rtrim($match[1], ':'));
                $exports += $this->flattenUseTree($match[2], [...$prefix, ...$nested]);
            } elseif (preg_match('/^([\w:]+?)(?:\s+as\s+(\w+))?$/', $part, $match)) {
                $path = [...$prefix, ...explode('::', $match[1])];
                $exports[$match[2] ?? end($path)] = $path;
            }
        }

        return $exports;
    }

    /**
     * Names of the <placeholders> in a Rocket path or query.
     *
     * @return list<string>
     */
    private function placeholders(string $path): array
    {
        preg_match_all('/<(\w+)(?:\.\.)?>/', $path, $matches);

        return $matches[1];
    }

    private function join(string $base, string $path): string
    {
        if ($base === '/' || $base === '') {
            return $path;
        }

        return $path === '/' ? $base : $base.$path;
    }

    /**
     * Rocket's <name> and <name..> segments in Laravel's {name} notation.
     */
    private function normalize(string $path): string
    {
        return (string) preg_replace('/<(\w+)(\.\.)?>/', '{$1$2}', $path);
    }
}
