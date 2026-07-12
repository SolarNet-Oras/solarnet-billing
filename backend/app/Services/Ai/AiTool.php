<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * Contract for every AI-callable tool.
 *
 * Each concrete tool describes itself with an OpenAI-style function schema
 * (name / description / parameters) and knows how to execute against the
 * ISP Billing domain when the model chooses to invoke it.
 */
interface AiTool
{
    /** Snake_case identifier used in tool_call.name. */
    public function name(): string;

    /**
     * OpenAI function-calling schema — MUST match the OpenAI JSON shape:
     *   [
     *     'type'     => 'function',
     *     'function' => [
     *       'name'        => 'get_customer_details',
     *       'description' => '...',
     *       'parameters'  => [ 'type' => 'object', 'properties' => [...], 'required' => [...] ],
     *     ],
     *   ]
     */
    public function schema(): array;

    /**
     * RBAC gate. Return false to have the executor return "permission_denied"
     * to the model without running the tool.
     */
    public function authorize(User $user): bool;

    /**
     * Execute the tool. Return an associative array — will be JSON-encoded
     * and sent back to the model as the tool's response.
     */
    public function execute(User $user, array $arguments): array;
}
