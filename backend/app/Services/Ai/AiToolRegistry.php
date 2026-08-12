<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\GetCustomerDetailsTool;
use App\Services\Ai\Tools\GetNetworkStatusTool;
use App\Services\Ai\Tools\ListCustomersTool;
use App\Services\Ai\Tools\ListSourceFilesTool;
use App\Services\Ai\Tools\ListUnregisteredLeasesTool;
use App\Services\Ai\Tools\ReadSourceFileTool;
use App\Services\Ai\Tools\SearchByMacOrIpTool;
use App\Services\Ai\Tools\SearchCodeTool;
use App\Services\Ai\Tools\PrepareCustomerStatusChangeTool;
use App\Services\Ai\Tools\PrepareServicePlanTool;
use App\Services\Ai\Tools\ConfirmPendingAiActionTool;

/**
 * Registry of all AI-callable tools.
 * Wave 1: 5 READ-ONLY tools. Wave 2+ adds MikroTik actions, Facebook posting, etc.
 */
class AiToolRegistry
{
    /** @var array<string, AiTool> */
    protected array $tools = [];

    public function __construct()
    {
        $this->register(new GetNetworkStatusTool());
        $this->register(new ListCustomersTool());
        $this->register(new GetCustomerDetailsTool());
        $this->register(new SearchByMacOrIpTool());
        $this->register(new ListUnregisteredLeasesTool());
        $this->register(new PrepareCustomerStatusChangeTool());
        $this->register(new PrepareServicePlanTool());
        $this->register(new ConfirmPendingAiActionTool());

        // Super-admin-only code exploration tools (read-only, guarded).
        // Their authorize() rejects non-super-admins so they simply won't
        // appear in the tool list for other roles.
        $this->register(new ReadSourceFileTool());
        $this->register(new ListSourceFilesTool());
        $this->register(new SearchCodeTool());
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<string, AiTool> */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Return OpenAI tool schemas for tools the given user is authorized to use.
     */
    public function schemasFor(\App\Models\User $user): array
    {
        $schemas = [];
        foreach ($this->tools as $tool) {
            if ($tool->authorize($user)) {
                $schemas[] = $tool->schema();
            }
        }
        return $schemas;
    }
}
