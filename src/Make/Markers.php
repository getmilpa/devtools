<?php

declare(strict_types=1);

namespace Milpa\DevTools\Make;

/**
 * The wiring-marker anchor names a RUNTIME plugin stub carries — stable `// {marker}` comment
 * tokens the composite generators ({@see Generators\ControllerGenerator}, {@see Generators\CrudGenerator},
 * {@see Generators\ServiceGenerator}, {@see Generators\ToolGenerator}) search for (via
 * {@see MarkerInserter}) and insert their registration snippet before, when targeting an EXISTING
 * plugin file that already carries the matching anchor. A plugin file that lacks a given marker (a
 * hand-written plugin, or one generated before F1's marker support) falls back to the pre-marker
 * guidance-only behavior for that concern — see each generator's own `wireX()` method.
 *
 * Every RUNTIME "fresh plugin" stub this package renders ({@see Generators\PluginGenerator}'s
 * standalone stub, and each composite generator's own "no plugin yet" stub) embeds the anchor(s)
 * relevant to what it already registers, so a LATER `coa:make` run targeting that same plugin can
 * keep auto-wiring into it — this is what closes the F1 greenhouse friction end-to-end, not just for
 * the very first artifact wired into a plugin.
 */
final class Markers
{
    /**
     * Inside `boot()`: DI service/repository registrations — {@see Generators\ServiceGenerator} and
     * {@see Generators\CrudGenerator} (its repository + controller registration) insert here.
     */
    public const SERVICES = 'coa:services';

    /**
     * Inside `routes(): array`'s return list: `Milpa\Http\Routing\Route` entries —
     * {@see Generators\ControllerGenerator} and {@see Generators\CrudGenerator} (its 5 REST routes)
     * insert here.
     */
    public const ROUTES = 'coa:routes';

    /**
     * Inside `registerTools(ToolRegistryInterface $registry): void`: `ToolScanner` scan calls —
     * {@see Generators\ToolGenerator} inserts here.
     */
    public const TOOLS = 'coa:tools';

    /**
     * Inside `getPromptSections(): array`'s return list: prompt-section string entries —
     * {@see Generators\ToolGenerator} inserts here, alongside {@see TOOLS}.
     */
    public const TOOL_PROMPTS = 'coa:tool-prompts';

    /** A static catalog of marker names — never instantiated. */
    private function __construct()
    {
    }
}
