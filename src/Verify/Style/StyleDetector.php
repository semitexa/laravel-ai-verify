<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Verify\Style;

use Semitexa\LaravelAiVerify\Support\Workspace;

/**
 * Finds the project's code standard. A config file is what makes a tool the
 * standard: Pint ships with every new Laravel app, so its presence alone says
 * nothing, and running it on a project that keeps its rules in
 * .php-cs-fixer.php or phpcs.xml would hold the agent to someone else's style.
 *
 *   'auto'                — every tool whose config file exists; without one, Pint's laravel preset if Pint is installed
 *   'phpcs', ['phpcs', …] — exactly these tools
 *   false / 'none'        — no style check
 */
final class StyleDetector
{
    public function __construct(private readonly Workspace $workspace) {}

    /** @return list<StyleTool> in detection order */
    public static function tools(): array
    {
        return [new PintTool, new PhpCsFixerTool, new PhpcsTool, new EcsTool];
    }

    public static function tool(string $id): ?StyleTool
    {
        foreach (self::tools() as $tool) {
            if ($tool->id() === $id) {
                return $tool;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(static fn (StyleTool $t) => $t->id(), self::tools());
    }

    /**
     * `none` says why nothing will run when `setups` is empty.
     *
     * @return array{setups: list<StyleSetup>, none: ?string, warnings: list<string>}
     */
    public function detect(mixed $setting): array
    {
        if ($setting === false || $setting === null || $setting === 'none' || $setting === 'off') {
            return ['setups' => [], 'none' => 'style check is off (ai-verify.style)', 'warnings' => []];
        }

        $wanted = is_array($setting) ? $setting : array_map('trim', explode(',', (string) $setting));
        $wanted = array_values(array_filter(array_map('strval', $wanted), static fn ($id) => $id !== ''));

        return $wanted === [] || $wanted === ['auto'] ? $this->auto() : $this->explicit($wanted);
    }

    /** @return array{setups: list<StyleSetup>, none: ?string, warnings: list<string>} */
    private function auto(): array
    {
        $setups = [];

        foreach (self::tools() as $tool) {
            if (($config = $this->configOf($tool)) !== null) {
                $setups[] = new StyleSetup($tool, $this->workspace->bin($tool->binary()), $config, "found {$config}");
            }
        }

        if ($setups !== []) {
            return ['setups' => $setups, 'none' => null, 'warnings' => array_values(array_filter(array_map(static fn (StyleSetup $s) => $s->missing(), $setups)))];
        }

        $pint = new PintTool;

        if (($bin = $this->workspace->bin($pint->binary())) !== null) {
            return ['setups' => [new StyleSetup($pint, $bin, null, 'no style config found; Pint is installed, so its laravel preset applies')], 'none' => null, 'warnings' => []];
        }

        return ['setups' => [], 'none' => 'no code style tool found (pint.json, .php-cs-fixer.php, phpcs.xml or ecs.php)', 'warnings' => []];
    }

    /**
     * @param  list<string>  $wanted
     * @return array{setups: list<StyleSetup>, none: ?string, warnings: list<string>}
     */
    private function explicit(array $wanted): array
    {
        $setups = [];
        $warnings = [];

        foreach ($wanted as $id) {
            $tool = self::tool($id);

            if ($tool === null) {
                $warnings[] = "unknown style tool '{$id}' in ai-verify.style (known: ".implode(', ', self::ids()).')';

                continue;
            }

            $setup = new StyleSetup($tool, $this->workspace->bin($tool->binary()), $this->configOf($tool), 'named in ai-verify.style');
            $setups[] = $setup;

            if ($setup->missing() !== null) {
                $warnings[] = $setup->missing();
            }
        }

        return ['setups' => $setups, 'none' => $setups === [] ? 'no known tool in ai-verify.style' : null, 'warnings' => $warnings];
    }

    private function configOf(StyleTool $tool): ?string
    {
        foreach ($tool->configFiles() as $file) {
            if ($this->workspace->exists($file)) {
                return $file;
            }
        }

        return null;
    }
}
