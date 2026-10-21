<?php

namespace App\Services\Carbon;

use App\Models\CarbonMcpTool;
use App\Services\Technician\PromptFence;
use App\Support\CarbonMcpConfig;
use Illuminate\Support\Facades\Log;

/**
 * Executes one Carbon Connect MCP catalogue tool as a passthrough (card
 * PEYvh4NL), the CippMcpDynamicToolExecutor pattern without the tenant layer:
 * a Carbon tool acts on the MSP's own Carbon account, so there is no client_id.
 *
 * Reaching this executor means McpStaffController::toolAllowed() already found
 * an EXPLICIT grant of this exact tool on the calling token and the tool live.
 * There is deliberately no further approval gate (Charlie's ruling, 08:57 PT
 * 10/21): the grant is the operator's decision.
 *
 * Logging and audit: the returned `error` string becomes the audit row's
 * error_message, so it is always PSA-authored (status / code / class). Carbon's
 * own text, success or tool error, is returned fenced in other keys for the
 * agent and is never logged.
 */
class CarbonMcpToolExecutor
{
    /** Characters of Carbon output handed back to the agent. */
    public const MAX_RESULT_CHARS = 60000;

    public function __construct(
        private readonly CarbonMcpClient $client,
        private readonly PromptFence $fence,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $toolName, array $input, bool $hasClientIdArgument): array
    {
        $tool = CarbonMcpTool::query()->active()->where('local_name', $toolName)->first();
        if ($tool === null) {
            return ['error' => "Unknown Carbon MCP catalogue tool: {$toolName}"];
        }

        if (! CarbonMcpConfig::isLive()) {
            return ['error' => 'Carbon Connect MCP is switched off or not signed in, so this tool was not run.'];
        }

        if ($hasClientIdArgument) {
            return ['error' => "client_id must be omitted for {$toolName}: Carbon tools act on the MSP's own Carbon account, not a PSA client's. Nothing was sent to Carbon."];
        }

        $schema = $tool->publicInputSchema();
        $properties = array_keys((array) ($schema['properties'] ?? []));
        $unknown = array_values(array_diff(array_keys($input), $properties));
        if ($unknown !== []) {
            return ['error' => 'Unsupported Carbon MCP argument(s): '.implode(', ', $unknown).'. Nothing was sent to Carbon.'];
        }
        $missing = array_values(array_filter(
            (array) ($schema['required'] ?? []),
            fn (mixed $key): bool => is_string($key) && ! array_key_exists($key, $input),
        ));
        if ($missing !== []) {
            return ['error' => 'Missing Carbon MCP argument(s): '.implode(', ', $missing).'. Nothing was sent to Carbon.'];
        }

        try {
            $result = $this->client->callTool($tool->upstream_name, $input);
        } catch (CarbonMcpAuthException $e) {
            Log::warning('[CarbonMcpToolExecutor] Carbon MCP sign-in failed', ['tool' => $toolName, 'error' => $e->getMessage()]);

            return ['error' => 'Carbon tool unavailable: PSA could not sign in to Carbon MCP ('.$e->getMessage().'). An admin reconnects at Settings > Integrations > Carbon Connect.'];
        } catch (CarbonMcpException $e) {
            Log::warning('[CarbonMcpToolExecutor] Carbon MCP call failed', ['tool' => $toolName, 'error' => $e->getMessage()]);

            return ['error' => 'Carbon tool call failed: '.$e->getMessage().'. If the tool call went out, it may have taken effect at Carbon; check Carbon before retrying a write.'];
        } catch (\Throwable $e) {
            Log::warning('[CarbonMcpToolExecutor] Carbon MCP call failed', ['tool' => $toolName, 'exception' => class_basename($e)]);

            return ['error' => 'Carbon tool call failed ('.class_basename($e).'). If the tool call went out, it may have taken effect at Carbon; check Carbon before retrying a write.'];
        }

        $text = $this->resultText($result);

        if (($result['isError'] ?? false) === true) {
            return [
                'error' => "Carbon reported an error for {$tool->upstream_name}; its text is in carbon_error.",
                'carbon_error' => $this->fence->fence('CARBON TOOL ERROR', $text),
            ];
        }

        return [
            'tool' => $tool->local_name,
            'upstream_tool' => $tool->upstream_name,
            'read_only' => (bool) $tool->read_only,
            'result' => $this->fence->fence('CARBON TOOL RESULT', $text),
        ];
    }

    /**
     * The tool's text content; when there is none, its structuredContent as
     * JSON. Capped, with the cap stated, never silently cut.
     *
     * @param  array<string, mixed>  $result
     */
    private function resultText(array $result): string
    {
        $text = '';
        foreach ((array) ($result['content'] ?? []) as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $text .= ($text === '' ? '' : "\n").$part['text'];
            }
        }

        if ($text === '' && array_key_exists('structuredContent', $result)) {
            $text = (string) json_encode($result['structuredContent'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($text === '') {
            return '(Carbon returned no text content)';
        }

        if (mb_strlen($text) > self::MAX_RESULT_CHARS) {
            return mb_substr($text, 0, self::MAX_RESULT_CHARS)."\n[PSA cut this result at ".self::MAX_RESULT_CHARS.' characters; ask Carbon for a narrower result]';
        }

        return $text;
    }
}
