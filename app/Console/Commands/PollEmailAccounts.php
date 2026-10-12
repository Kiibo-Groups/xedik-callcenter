<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\NewMessageReceived;
use App\Models\AgentSession;
use App\Models\Attachment;
use App\Models\EmailAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Lead;
use App\Services\Storage\CrmFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Webklex\PHPIMAP\ClientManager;

ini_set('memory_limit', '512M');

class PollEmailAccounts extends Command
{
    protected $signature   = 'email:poll';
    protected $description = 'Poll all active email accounts for new messages';

    public function __construct(private readonly CrmFileService $fileService)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $accounts = EmailAccount::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                                ->with('omniQueue')
                                ->where('active', true)
                                ->get();

        if ($accounts->isEmpty()) {
            $this->info('No active email accounts found.');
            return;
        }

        foreach ($accounts as $account) {
            $this->pollAccount($account);
        }
    }

    private function pollAccount(EmailAccount $account): void
    {
        $this->info("Polling: {$account->email}");

        try {
            $cm = new ClientManager();

            $client = $cm->make([
                'host'          => $account->imap_host,
                'port'          => $account->imap_port,
                'encryption'    => strtolower($account->imap_security) === 'none' ? false : strtolower($account->imap_security),
                'validate_cert' => false,
                'username'      => $account->email,
                'password'      => $account->password,
                'protocol'      => 'imap',
            ]);

            $client->connect();

            $folder   = $client->getFolder('INBOX');
            $messages = $folder->query()->unseen()
                                        ->since(now()->startOfDay())
                                        ->setFetchOrder('desc')
                                        ->get();

            $this->info("  Found " . $messages->count() . " unread messages");

            foreach ($messages as $mail) {
                $this->processEmail($mail, $account);
            }

            $account->update(['last_checked_at' => now()]);
            $client->disconnect();

        } catch (\Throwable $e) {
            Log::error("PollEmailAccounts: error polling {$account->email} — " . $e->getMessage());
            $this->error("  Error: " . $e->getMessage());
        }
    }

    private function processEmail($mail, EmailAccount $account): void
    {
        try {
            $messageId = $mail->getMessageId()->first() ?? null;
            $inReplyTo = $mail->getInReplyTo()->first()  ?? null;
            $ccRaw     = $mail->getCc();
            $cc        = '';

            if ($ccRaw && method_exists($ccRaw, 'all') && count($ccRaw->all()) > 0) {
                $cc = collect($ccRaw->all())->map(fn($r) => $r->mail ?? '')->filter()->implode(', ');
            }

            $toEmail   = collect($mail->getTo()->all())->map(fn($r) => $r->mail ?? '')->filter()->implode(', ');
            $fromEmail = $mail->getFrom()->first()?->mail ?? '';
            $fromName  = $mail->getFrom()->first()?->personal ?? '';
            $subject   = $mail->getSubject()->first() ?? '(no subject)';
            $rawDate   = $mail->getDate()->first() ?? now();
            $emailDate = \Carbon\Carbon::parse($rawDate)->setTimezone('America/Hermosillo');

            $mail->setFlag('Seen');

            if ($messageId && Message::where('message_id', $messageId)
                                     ->where('tenant_id', $account->tenant_id)
                                     ->exists()) {
                $this->line("  Skipping duplicate: {$messageId}");
                return;
            }

            $conversation    = null;
            $previousAgentId = null;

            if ($inReplyTo && $inReplyTo !== '0') {
                $cleanInReplyTo = trim($inReplyTo, '<>');
                $parentMessage  = Message::where('message_id', $cleanInReplyTo)
                                         ->where('tenant_id', $account->tenant_id)
                                         ->first();
                $existingConv   = $parentMessage?->conversation;

                if ($existingConv && $existingConv->status !== 'resolved') {
                    $conversation    = $existingConv;
                    $previousAgentId = $existingConv->assigned_to;
                }
            }

            if (!$conversation) {
                $lead = Lead::where('email', $fromEmail)
                            ->where('tenant_id', $account->tenant_id)
                            ->first();

                $conversation = Conversation::create([
                    'tenant_id'        => $account->tenant_id,
                    'channel'          => 'email',
                    'email_account_id' => $account->id,
                    'omni_queue_id'    => $account->omni_queue_id,
                    'lead_id'          => $lead?->id,
                    'from_email'       => $fromEmail,
                    'from_name'        => $fromName,
                    'subject'          => $subject,
                    'status'           => 'pending',
                    'wait_since'       => $emailDate,
                ]);

                Log::info("PollEmailAccounts: new conversation #{$conversation->id} from {$fromEmail}");
            }

            Conversation::withoutTimestamps(fn() => $conversation->update(['wait_since' => now()]));

            if ($previousAgentId) {
                $agentReady = AgentSession::where('user_id', $previousAgentId)
                                          ->where('status', 'ready')
                                          ->exists();

                if (!$agentReady) {
                    $conversation->update(['status' => 'pending', 'wait_since' => now()]);
                    Log::info("PollEmailAccounts: conversation #{$conversation->id} — agent #{$previousAgentId} not ready, held pending");
                }
            }

            $message = Message::create([
                'tenant_id'       => $account->tenant_id,
                'conversation_id' => $conversation->id,
                'direction'       => 'inbound',
                'from_email'      => $fromEmail,
                'from_name'       => $fromName,
                'to_email'        => $toEmail ?: $account->email,
                'cc_emails'       => $cc ?: null,
                'body_html'       => $mail->getHTMLBody() ?: null,
                'body_text'       => $mail->getTextBody() ?: null,
                'message_id'      => $messageId,
                'in_reply_to'     => $inReplyTo,
                'sent_at'         => $emailDate,
            ]);

            if ($conversation->assigned_to && $conversation->status === 'active') {
                $agentReady = AgentSession::where('user_id', $conversation->assigned_to)
                                          ->where('status', 'ready')
                                          ->exists();
                if ($agentReady) {
                    event(new NewMessageReceived($message, $conversation));
                }
            }

            // ── EML → R2 ─────────────────────────────────────────────────────
            $this->saveEmlToR2($mail, $message, $account);

            // ── Adjuntos ─────────────────────────────────────────────────────
            $attachments = $mail->getAttachments();
            foreach ($attachments as $attachment) {
                $this->processAttachment($attachment, $message, $account);
            }

            $this->info("  Saved message from {$fromEmail}: {$subject}");
            $mail = null;
            gc_collect_cycles();

        } catch (\Throwable $e) {
            Log::error("PollEmailAccounts: error processing email — " . $e->getMessage());
            $this->error("  Error processing email: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function saveEmlToR2($mail, Message $message, EmailAccount $account): void
    {
        try {
            $eml     = $mail->getHeader()->raw . "\r\n" . $mail->getRawBody();
            $tmpPath = sys_get_temp_dir() . '/' . \Str::uuid() . '.eml';
            file_put_contents($tmpPath, $eml);

            $this->fileService->uploadFile($account->tenant_id, $tmpPath, [
                'channel'         => 'email',
                'category'        => 'raw',
                'source'          => 'email',
                'direction'       => 'inbound',
                'conversation_id' => $message->conversation_id,
                'message_id'      => $message->id,
            ]);

            @unlink($tmpPath);
            unset($eml);

        } catch (\Throwable $e) {
            Log::warning("PollEmailAccounts: EML upload to R2 failed for msg #{$message->id} — " . $e->getMessage());
        }
    }

    private function processAttachment($attachment, Message $message, EmailAccount $account): void
    {
        $filename   = $attachment->getName() ?? 'file';
        $mimeType   = $attachment->getMimeType() ?? 'application/octet-stream';
        $content    = $attachment->getContent();
        $size       = strlen($content);
        $contentId  = $attachment->getId() ?? null;
        $disposition = $attachment->getDisposition() ?? 'attachment';
        $partNumber = $attachment->getPartNumber() ?? null;
        $safeName   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // ── Normalizar Content-ID ─────────────────────────────────────────────
        // Gmail: "ii_mpyzwe2s1@mail.gmail.com" → base "ii_mpyzwe2s1"
        $cleanCid = $contentId ? trim($contentId, '<> ') : null;
        $baseCid  = ($cleanCid && str_contains($cleanCid, '@'))
            ? strstr($cleanCid, '@', true)
            : $cleanCid;

        // Determinar si es imagen inline referenciada en el body
        $referencedInBody = $cleanCid && $message->body_html && (
            str_contains($message->body_html, 'cid:' . $cleanCid) ||
            ($baseCid && str_contains($message->body_html, 'cid:' . $baseCid))
        );

        $isInline = !empty($cleanCid) && (
            strtolower($disposition) === 'inline' || $referencedInBody
        );

        if ($isInline && !pathinfo($safeName, PATHINFO_EXTENSION)) {
            $ext = explode('/', $mimeType)[1] ?? 'bin';
            $safeName .= '.' . $ext;
        }

        if ($isInline) {
            // ── Inline image → local (igual que antes) ────────────────────────
            $subdir = 'inline';
            $path   = 'messages/inbound/' . $message->id . '/' . $subdir . '/tmp_' . $safeName;
            Storage::disk('local')->put($path, $content);

            $att = Attachment::create([
                'tenant_id'        => $account->tenant_id,
                'message_id'       => $message->id,
                'filename'         => $filename,
                'mime_type'        => $mimeType,
                'size'             => $size,
                'path'             => $path,
                'content_id'       => $contentId,
                'imap_part_number' => $partNumber,
            ]);

            $finalPath = 'messages/inbound/' . $message->id . '/' . $subdir . '/' . $att->id . '_' . $safeName;
            Storage::disk('local')->move($path, $finalPath);
            $att->update(['path' => $finalPath]);

            // ── Fix CID: reemplazar tanto el CID completo como el base ────────
            if ($message->body_html) {
                $url        = \URL::signedRoute('attachments.view', ['attachment' => $att->id]);
                $bodyUpdated = $message->body_html;
                $bodyUpdated = str_replace('cid:' . $cleanCid, $url, $bodyUpdated);
                if ($baseCid && $baseCid !== $cleanCid) {
                    $bodyUpdated = str_replace('cid:' . $baseCid, $url, $bodyUpdated);
                }
                if ($bodyUpdated !== $message->body_html) {
                    $message->body_html = $bodyUpdated;
                    $message->save();
                }
            }

            $this->info("  Saved inline: {$filename}");

        } else {
            // ── Adjunto normal → R2 ──────────────────────────────────────────
            $ext     = strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) ?: null;
            $tmpPath = sys_get_temp_dir() . '/' . \Str::uuid() . ($ext ? '.' . $ext : '');
            file_put_contents($tmpPath, $content);

            try {
                $crmFile = $this->fileService->uploadFile($account->tenant_id, $tmpPath, [
                    'channel'         => 'email',
                    'category'        => $this->fileService->normalizeCategoryFromMimeType($mimeType, $ext),
                    'source'          => 'email_attachment',
                    'direction'       => 'inbound',
                    'conversation_id' => $message->conversation_id,
                    'message_id'      => $message->id,
                ]);

                Attachment::create([
                    'tenant_id'        => $account->tenant_id,
                    'message_id'       => $message->id,
                    'filename'         => $filename,
                    'mime_type'        => $mimeType,
                    'size'             => $size,
                    'path'             => '',
                    'content_id'       => $contentId,
                    'imap_part_number' => $partNumber,
                    'crm_file_id'      => $crmFile->id,
                ]);

                $this->info("  Saved attachment → R2: {$filename}");

            } catch (\Throwable $e) {
                Log::warning("PollEmailAccounts: R2 upload failed for {$filename} — " . $e->getMessage());
            } finally {
                @unlink($tmpPath);
            }
        }
    }
}
