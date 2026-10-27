<?php

namespace App\Config;

use Closure;

/**
 * One entry of Vaultwarden's `make_config!` (src/config.rs).
 */
final readonly class Setting
{
    /**
     * @param  'def'|'auto'|'option'|'generated'  $action  What happens when no value is given:
     *                                                     def uses $default, auto and generated compute it from the
     *                                                     settings before it (generated even ignores a given value),
     *                                                     option leaves it null
     * @param  string|int|bool|(Closure(array<string, string|int|bool|null>): (string|int|bool|null))|null  $default
     */
    public function __construct(
        public string $name,
        public string $group,
        public SettingType $type,
        public bool $editable,
        public string $action,
        public string|int|bool|Closure|null $default = null,
    ) {}

    /**
     * The environment variable, which is the upper-cased name.
     */
    public function env(): string
    {
        return strtoupper($this->name);
    }
}
