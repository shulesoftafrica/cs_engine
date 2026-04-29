<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class IntentClassifierAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
Classify the user message into one of these intents only:
SUPPORT, ADVISORY, BUG_REPORT, FEATURE_REQUEST, GREETING, UNKNOWN.

Use SUPPORT only when the user is asking for product help, troubleshooting, how-to guidance, or an answer that can be grounded in support knowledge.
Return only the intent field.
PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intent' => $schema->string()
                ->enum(['SUPPORT', 'ADVISORY', 'BUG_REPORT', 'FEATURE_REQUEST', 'GREETING', 'UNKNOWN'])
                ->required(),
        ];
    }
}
