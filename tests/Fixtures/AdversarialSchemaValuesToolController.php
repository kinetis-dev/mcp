<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\Regex;

/**
 * KINETIS-76 second follow-up: real schema string values that
 * deliberately equal McpRegistry's own retired string marker
 * ("__kinetis_mcp_empty_object__") — proves the reserved-key array
 * envelope that replaced it doesn't mistake an ordinary #[In] choice or
 * #[Regex] pattern string for the marker the way the old bare-string
 * comparison would have.
 */
final readonly class AdversarialSchemaValuesToolController
{
    #[McpTool(name: 'adversarial_schema_values', description: 'Exercises schema string values matching the retired marker literal')]
    public function run(
        #[In(['__kinetis_mcp_empty_object__', 'other'])]
        string $choice,
        #[Regex('__kinetis_mcp_empty_object__')]
        string $pattern,
    ): array {
        return ['choice' => $choice, 'pattern' => $pattern];
    }
}
