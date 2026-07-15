<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessChatTurnJob;
use App\Services\Webchat\Jsonl\WebchatBusyException;
use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\Jsonl\WebchatThreadLock;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatEventStreamer;
use App\Services\Webchat\WebchatFloorLockedException;
use App\Services\Webchat\WebchatRateLimitedException;
use App\Services\Webchat\WebchatRedactSecrets;
use App\Services\Webchat\WebchatSpeakFloor;
use App\Services\Webchat\WebchatTitleService;
use App\Services\Webchat\WebchatTurnRateLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AipediaWebchatController extends Controller
{
    private function admin(Request $request): array
    {
        return [
            'admin_user_id' => (int) ($request->session()->get('admin_user_id') ?: 1),
            'role_id' => (int) ($request->session()->get('role_id') ?: 1),
            'permissions' => [],
            'session_id' => $request->session()->getId(),
            'admin_display_name' => $request->session()->get('admin_display_name') ?: 'Admin',
            'admin_role_name' => $request->session()->get('admin_role_name') ?: 'admin',
            'locale' => 'id',
            'cms_environment' => 'local',
            'active_domain' => '',
            'allowed_tool_domains' => 'docs',
            'pii_redaction' => 'false',
            'current_admin_path' => '',
            'last_entity_ref' => '',
            'conversation_goal' => '',
        ];
    }

    public function index(Request $request)
    {
        return view('aipedia.webchat.chat', [
            'adminUserId' => $this->admin($request)['admin_user_id'],
        ]);
    }

    public function listConversations(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        if ($admin['admin_user_id'] <= 0) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);

        return response()->json([
            'conversations' => $store->listActiveConversations(),
        ]);
    }

    public function createThread(Request $request): JsonResponse
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $admin = $this->admin($request);

        $threadId = 'thr_' . $this->newUlid();
        $store->appendSessionIndex($threadId, $admin['admin_user_id'], null);
        $line = $store->appendThreadLine(new \App\Services\Webchat\Jsonl\JsonlLine([
            'ts' => microtime(true),
            'thread_id' => $threadId,
            'type' => 'thread.started',
            'id' => 'itm_' . $this->newUlid(),
        ]));

        return response()->json([
            'thread_id' => $threadId,
            'seq_head' => $line->seq,
            'created_by_admin_user_id' => $admin['admin_user_id'],
        ], 201);
    }

    public function getThread(Request $request, string $threadId): JsonResponse
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $lock = new WebchatThreadLock($cfg);
        $floor = new WebchatSpeakFloor($cfg, $store);
        $admin = $this->admin($request);

        $afterSeq = (int) $request->input('after_seq', 0);

        if (!$store->canAccessConversation($threadId, $admin['admin_user_id'])) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $lines = $store->readThread($threadId);
        $items = array_values(array_filter($lines, static fn ($l) => $l['seq'] > $afterSeq));
        $seqHead = 0;
        foreach ($lines as $l) {
            if ($l['seq'] > $seqHead) {
                $seqHead = $l['seq'];
            }
        }

        $row = $store->resolveConversation($threadId);

        return response()->json([
            'thread_id' => $threadId,
            'seq_head' => $seqHead,
            'busy' => $lock->isBusy($threadId),
            'floor_holder_admin_id' => isset($row['floor_holder_admin_id']) ? (int) $row['floor_holder_admin_id'] : null,
            'floor_remaining_sec' => $floor->floorRemainingSec($row, $admin['admin_user_id']),
            'active_turn_id' => $row['active_turn_id'] ?? null,
            'active_turn_initiator_admin_id' => isset($row['active_turn_initiator_admin_id'])
                ? (int) $row['active_turn_initiator_admin_id'] : null,
            'items' => $items,
        ]);
    }

    public function startTurn(Request $request, string $threadId): JsonResponse
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $lock = new WebchatThreadLock($cfg);
        $floor = new WebchatSpeakFloor($cfg, $store);
        $rl = new WebchatTurnRateLimit($cfg);
        $admin = $this->admin($request);

        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            return response()->json(['error' => 'empty'], 422);
        }

        if (!$store->canAccessConversation($threadId, $admin['admin_user_id'])) {
            return response()->json(['error' => 'not_found'], 404);
        }

        try {
            $rl->assert($admin['admin_user_id']);
        } catch (WebchatRateLimitedException $e) {
            return response()->json([
                'error' => 'rate_limited',
                'code' => 'rate_limited',
            ], 429)->header('Retry-After', (string) $e->retryAfterSec);
        }

        try {
            $floor->assert($threadId, $admin['admin_user_id']);
        } catch (WebchatFloorLockedException $e) {
            return response()->json([
                'error' => 'floor_locked',
                'code' => 'floor_locked',
                'holder_admin_user_id' => $e->holderAdminUserId,
                'remaining_sec' => $e->remainingSec,
            ], 423);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'not_found') {
                return response()->json(['error' => 'not_found'], 404);
            }
            throw $e;
        }

        $redacted = WebchatRedactSecrets::redact($message);
        $safeMessage = $redacted['redacted_text'];

        try {
            $lockRec = $lock->tryAcquire($threadId, 300);
        } catch (WebchatBusyException $e) {
            return response()->json(['error' => 'busy', 'code' => 'thread_busy'], 409);
        }

        $turnId = 'trn_' . $this->newUlid();
        $floor->acquire($threadId, $admin['admin_user_id'], $turnId);

        ProcessChatTurnJob::dispatch($threadId, $turnId, $safeMessage, $admin, $lockRec['token']);

        $lines = $store->readThread($threadId);
        $seqHead = 0;
        foreach ($lines as $l) {
            if ($l['seq'] > $seqHead) {
                $seqHead = $l['seq'];
            }
        }

        return response()->json([
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'seq_head' => $seqHead,
            'status' => 'queued',
            'floor_holder_admin_id' => $admin['admin_user_id'],
            'floor_remaining_sec' => 0,
        ], 202);
    }

    public function events(Request $request, string $threadId)
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $admin = $this->admin($request);

        if (!$store->canAccessConversation($threadId, $admin['admin_user_id'])) {
            return response("event: error\ndata: {\"message\":\"forbidden\"}\n\n", 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $afterSeq = (int) $request->input('after_seq', 0);

        $streamer = new WebchatEventStreamer($cfg, $store);
        return new StreamedResponse(function () use ($streamer, $threadId, $afterSeq) {
            $streamer->stream($threadId, $afterSeq, static function ($bytes) {
                echo $bytes;
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                @flush();
            });
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function rename(Request $request, string $threadId): JsonResponse
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $titles = new WebchatTitleService($cfg, $store);
        $admin = $this->admin($request);

        if (!$store->canAccessConversation($threadId, $admin['admin_user_id'])) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $title = trim((string) $request->input('title', ''));
        if ($title === '' || mb_strlen($title) > 60) {
            return response()->json(['error' => 'validation', 'code' => 'invalid_title'], 422);
        }

        try {
            $titles->apply($threadId, $admin['admin_user_id'], $title, 'manual');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'deleted') {
                return response()->json(['error' => 'not_found'], 404);
            }
            throw $e;
        }

        return response()->json([
            'thread_id' => $threadId,
            'title' => mb_substr($title, 0, 60),
        ]);
    }

    public function interrupt(Request $request, string $threadId): JsonResponse
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $admin = $this->admin($request);

        if (!$store->canAccessConversation($threadId, $admin['admin_user_id'])) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $row = $store->resolveConversation($threadId);
        $turnId = $request->input('turn_id') ?: ($row['active_turn_id'] ?? null);
        $initiator = isset($row['active_turn_initiator_admin_id'])
            ? (int) $row['active_turn_initiator_admin_id'] : null;

        if (empty($turnId) || $initiator !== $admin['admin_user_id']) {
            return response()->json(['error' => 'forbidden', 'code' => 'not_initiator'], 403);
        }

        $flagPath = $cfg->storageRoot . '/interrupt/' . $threadId . '/' . $turnId . '.flag';
        $dir = dirname($flagPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($flagPath, '1');

        return response()->json(['ok' => true, 'status' => 'interrupt_requested'], 202);
    }

    private function newUlid(): string
    {
        return strtolower(uniqid('ulid_', true));
    }
}
