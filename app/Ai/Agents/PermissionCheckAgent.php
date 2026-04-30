<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class PermissionCheckAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<string, string>  $productPermissions
     */
    public function __construct(
        private readonly array $productPermissions,
    ) {}

    public function instructions(): Stringable|string
    {
        $permissionsJson = json_encode($this->productPermissions, JSON_PRETTY_PRINT) ?: '{}';

        return <<<PROMPT
You extract required product permission keys from a user prompt.

Rules:
- You are given the ONLY valid permission keys and labels for this product.
- Return only permission keys that are clearly required by the user's message intent.
- Do not invent keys.
- If no permission is clearly required, return an empty array.
- Output must follow the provided schema.

Available product permissions (key => label):
{$permissionsJson}
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'required_permissions' => $schema->array()
                ->items($schema->string())
                ->required(),
        ];
    }
}
