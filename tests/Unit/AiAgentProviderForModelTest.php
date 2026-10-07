<?php

namespace Tests\Unit;

use App\Services\AiAgentService;
use PHPUnit\Framework\TestCase;

class AiAgentProviderForModelTest extends TestCase
{
    public function test_gemini_model_overrides_openai_provider(): void
    {
        $this->assertSame('gemini', AiAgentService::providerForModel('openai', 'gemini-2.5-flash-lite'));
        $this->assertSame('anthropic', AiAgentService::providerForModel('openai', 'claude-sonnet-4-20250514'));
        $this->assertSame('openai', AiAgentService::providerForModel('openai', 'gpt-4o-mini'));
    }
}
