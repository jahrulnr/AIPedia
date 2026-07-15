<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessChatTurnJob;
use App\Services\Webchat\Jsonl\WebchatBusyException;
use App\Services\Webchat\Jsonl\WebchatJsonlStore;
use App\Services\Webchat\Jsonl\WebchatThreadLock;
use App\Services\Webchat\WebchatConfig;
use App\Services\Webchat\WebchatEventStreamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            'threads' => [],
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
        ], 201);
    }

    public function getThread(Request $request, string $threadId): JsonResponse
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $lock = new WebchatThreadLock($cfg);
        $admin = $this->admin($request);

        $afterSeq = (int) $request->input('after_seq', 0);

        if (!$store->ownsThread($threadId, $admin['admin_user_id'])) {
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

        return response()->json([
            'thread_id' => $threadId,
            'seq_head' => $seqHead,
            'busy' => $lock->isBusy($threadId),
            'items' => $items,
        ]);
    }

    public function startTurn(Request $request, string $threadId): JsonResponse
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $lock = new WebchatThreadLock($cfg);
        $admin = $this->admin($request);

        $message = trim($request->input('message', ''));
        if ($message === '') {
            return response()->json(['error' => 'empty'], 422);
        }

        if (!$store->ownsThread($threadId, $admin['admin_user_id'])) {
            return response()->json(['error' => 'not_found'], 404);
        }

        try {
            $lockRec = $lock->tryAcquire($threadId, 300);
        } catch (WebchatBusyException $e) {
            return response()->json(['error' => 'busy'], 409);
        }

        $turnId = 'trn_' . $this->newUlid();
        ProcessChatTurnJob::dispatch($threadId, $turnId, $message, $admin, $lockRec['token']);

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
        ], 202);
    }

    public function events(Request $request, string $threadId)
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $admin = $this->admin($request);

        if (!$store->ownsThread($threadId, $admin['admin_user_id'])) {
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

    public function interrupt(Request $request, string $threadId): JsonResponse
    {
        $cfg = WebchatConfig::load();
        $store = new WebchatJsonlStore($cfg);
        $admin = $this->admin($request);
        $turnId = $request->input('turn_id');

        if (!$store->ownsThread($threadId, $admin['admin_user_id']) || empty($turnId)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $flagPath = storage_path('app/webchat/interrupt/' . $threadId . '/' . $turnId . '.flag');
        $dir = dirname($flagPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($flagPath, '1');

        return response()->json(['status' => 'interrupted']);
    }

    private function newUlid(): string
    {
        return strtolower(uniqid('ulid_', true));
    }
}
