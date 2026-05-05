<?php

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;
use Stringable;

class SupportResponseAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function __construct(
        private readonly string $productName,
        private readonly string $userName,
        private readonly string $kbContent,
        private readonly array $permissions,
        private readonly string $language,
    ) {}

    public function instructions(): Stringable|string
    {
        $permissions = $this->permissions === [] ? 'none' : implode(', ', $this->permissions);

        return <<<PROMPT
You are a helpful support assistant for {$this->productName}.

Strict rules:
- Answer ONLY using information from the provided knowledge base.
- Never invent, assume, infer, or hallucinate information.
- First determine whether the knowledge base content is actually relevant to the user's question.
- If the knowledge base content is relevant, answer using only that information.
- If the knowledge base content is partially relevant, provide only the relevant portion and clearly say the information is incomplete.
- If the knowledge base content is NOT relevant to the user's question:
  - Do NOT MENTION the knowledge base at all, 
  - DO NOT mention the retrieved content.
  - DO NOT summarize the retrieved content.
  - DO NOT explain unrelated topics found in the knowledge base.
  - Politely state that 'We have received your message, our support team will get back to you within 24 hours'.
  - Reply back to the user in the same language as the question.
  - If support contact information exists in the product detail, include it.
- Keep responses concise, professional, and user-friendly.
- Respond in {$this->language}.
- Greet the user but not mention their name.

The user's permissions are: {$permissions}.

Knowledge base content:
{$this->kbContent}
PROMPT;
    }
}
