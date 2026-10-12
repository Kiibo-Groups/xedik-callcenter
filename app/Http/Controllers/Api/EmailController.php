<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationReport;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Message;
use App\Models\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailController extends Controller
{
    // ── POST /api/emails/send-by-template ─────────────────────────────────────

    public function sendByTemplate(Request $request): JsonResponse
    {
        /** @var \App\Models\AccessToken $accessToken */
        $accessToken = $request->attributes->get('access_token');
        $tenantId    = $accessToken->tenant_id;

        $validated = $request->validate([
            'template_id'      => 'required|integer',
            'phone'            => 'required|string',
            'leadlist_id'      => 'required|integer',
            'email_account_id' => 'nullable|integer',
        ]);

        // 1. Template (validar tenant)
        $template = EmailTemplate::withoutGlobalScope(TenantScope::class)
            ->where('id', $validated['template_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$template) {
            return response()->json(['success' => false, 'error' => 'Template not found.'], 404);
        }

        // 2. Cuenta de email: específica o primera activa del tenant
        $accountQuery = EmailAccount::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('active', true);

        if (!empty($validated['email_account_id'])) {
            $accountQuery->where('id', $validated['email_account_id']);
        }

        $account = $accountQuery->first();

        if (!$account) {
            return response()->json(['success' => false, 'error' => 'No active email account found for this tenant.'], 422);
        }

        // 3. LeadList (validar tenant)
        $list = LeadList::withoutGlobalScope(TenantScope::class)
            ->where('id', $validated['leadlist_id'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$list) {
            return response()->json(['success' => false, 'error' => 'Lead list not found.'], 404);
        }

        // 4. Buscar lead en la tabla dinámica por phone
        $phone = preg_replace('/\D/', '', $validated['phone']);

        $row = DB::table($list->table_name)->where('phone', $phone)->first();

        if (!$row) {
            return response()->json([
                'success'    => false,
                'error'      => "No lead found with phone {$phone} in list \"{$list->name}\".",
                'lead_phone' => $phone,
            ], 422);
        }

        // 5. Extraer email desde custom_fields de la tabla dinámica
        $toEmail = $this->extractEmail($row);

        if (!$toEmail) {
            return response()->json([
                'success'    => false,
                'error'      => 'No email found for this lead.',
                'lead_phone' => $phone,
            ], 422);
        }

        // 6. Resolver variables (source del template determina de dónde vienen lead.*)
        $source = $template->variable_source ?? 'leads';
        $lead   = null;

        [$subject, $body, $resolveError] = $this->resolveTemplate(
            $template, $source, $phone, $tenantId, $list, $row, $lead
        );

        if ($resolveError !== null) {
            return response()->json(['success' => false, 'error' => $resolveError], 422);
        }

        // 7. Enviar email via SMTP
        try {
            [$mailer, $messageId] = $this->buildMailer($account);

            $fromEmail = $account->from_email ?: $account->email;
            $fromName  = $account->from_name  ?: null;

            $symfonyEmail = (new Email())
                ->from($fromName ? new Address($fromEmail, $fromName) : $fromEmail)
                ->to($toEmail)
                ->subject($subject)
                ->html($body)
                ->text(strip_tags($body));

            if ($account->reply_to) {
                $symfonyEmail->replyTo($account->reply_to);
            }

            $symfonyEmail->getHeaders()->addIdHeader('Message-ID', $messageId);

            $mailer->send($symfonyEmail);
        } catch (\Throwable $e) {
            Log::error('[API] sendByTemplate SMTP error', [
                'tenant_id'   => $tenantId,
                'template_id' => $template->id,
                'to_email'    => $toEmail,
                'error'       => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'error' => 'SMTP error: ' . $e->getMessage()], 500);
        }

        // 8. Registrar en BD (Conversation + Message)
        $sentAt = now();

        $conversation = Conversation::create([
            'tenant_id'        => $tenantId,
            'channel'          => 'email',
            'email_account_id' => $account->id,
            'omni_queue_id'    => $account->omni_queue_id,
            'lead_id'          => $lead?->id,
            'from_email'       => $account->email,
            'from_name'        => $lead?->full_name ?? null,
            'subject'          => $subject,
            'status'           => 'resolved',
            'direction'        => 'outbound',
            'wait_since'       => $sentAt,
            'assigned_at'      => $sentAt,
            'resolved_at'      => $sentAt,
        ]);

        Message::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'from_email'      => $account->email,
            'from_name'       => $account->from_name ?? $account->name,
            'to_email'        => $toEmail,
            'body_html'       => $body,
            'body_text'       => strip_tags($body),
            'message_id'      => $messageId,
            'sent_at'         => $sentAt,
        ]);

        ConversationReport::create([
            'tenant_id'              => $tenantId,
            'conversation_id'        => $conversation->id,
            'agent_id'               => null,
            'agent_name'             => 'System (API)',
            'lead_id'                => $lead?->id,
            'lead_name'              => $lead?->full_name ?? null,
            'contact_email'          => $toEmail,
            'channel'                => 'email',
            'direction'              => 'outbound',
            'subject'                => $subject,
            'first_message_preview'  => \Illuminate\Support\Str::limit(strip_tags($body), 100),
            'disposition'            => null,
            'message_count'          => 1,
            'inbound_message_count'  => 0,
            'outbound_message_count' => 1,
            'duration_seconds'       => 0,
            'wait_since'             => $sentAt,
            'resolved_at'            => $sentAt,
            'notes'                  => "Sent via API — template: {$template->name}",
        ]);

        return response()->json([
            'success'         => true,
            'message_id'      => $messageId,
            'conversation_id' => $conversation->id,
            'lead_id'         => $lead?->id,
            'to_email'        => $toEmail,
            'template_used'   => $template->name,
        ]);
    }

    // ── Resolución de variables ───────────────────────────────────────────────

    /**
     * @param ?Lead $leadOut  Populated when source=leads and a CRM lead is found.
     * @return array{string, string, ?string}  [subject, body, errorMessage]
     */
    private function resolveTemplate(
        EmailTemplate $template,
        string        $source,
        string        $phone,
        int           $tenantId,
        LeadList      $list,
        object        $row,
        ?Lead         &$leadOut
    ): array {
        $subject = $template->subject;
        $body    = $template->body;

        if ($source === 'leads') {
            $lead = Lead::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('phone', $phone)
                ->first();

            if (!$lead) {
                return [$subject, $body, "No CRM lead found with phone {$phone}."];
            }

            $leadOut = $lead;
            $subject = $this->resolveLeadVars($subject, $lead);
            $body    = $this->resolveLeadVars($body, $lead);

        } elseif ($source === 'leadlist') {
            $subject = $this->resolveLeadlistVars($subject, $row);
            $body    = $this->resolveLeadlistVars($body, $row);
        }

        // Variables de sistema siempre resueltas
        $subject = str_replace('{{date.today}}', now()->format('F j, Y'), $subject);
        $body    = str_replace('{{date.today}}', now()->format('F j, Y'), $body);

        // agent.* no disponible en contexto API
        $subject = preg_replace('/\{\{agent\.[^}]+\}\}/', '—', $subject);
        $body    = preg_replace('/\{\{agent\.[^}]+\}\}/', '—', $body);

        return [$subject, $body, null];
    }

    private function resolveLeadVars(string $text, Lead $lead): string
    {
        $map = [
            '{{lead.first_name}}' => $lead->first_name,
            '{{lead.last_name}}'  => $lead->last_name,
            '{{lead.email}}'      => $lead->email,
            '{{lead.phone}}'      => $lead->phone,
            '{{lead.phone_2}}'    => $lead->phone_2,
            '{{lead.address}}'    => $lead->address,
            '{{lead.city}}'       => $lead->city,
            '{{lead.state}}'      => $lead->state,
            '{{lead.zip}}'        => $lead->zip,
            '{{lead.country}}'    => $lead->country,
            '{{lead.status}}'     => $lead->status,
            '{{lead.source}}'     => $lead->source,
            '{{lead.value}}'      => $lead->value !== null ? (string) $lead->value : null,
            '{{lead.notes}}'      => $lead->notes,
        ];

        foreach ($map as $placeholder => $value) {
            $text = str_replace($placeholder, $value ?? '—', $text);
        }

        return $text;
    }

    private function resolveLeadlistVars(string $text, object $row): string
    {
        foreach (['phone', 'status'] as $col) {
            $text = str_replace(
                '{{leadlist.' . $col . '}}',
                (string)($row->$col ?? '—'),
                $text
            );
        }

        if (!empty($row->custom_fields)) {
            $cf = json_decode($row->custom_fields, true);
            if (is_array($cf)) {
                foreach ($cf as $key => $value) {
                    $text = str_replace(
                        '{{leadlist.' . $key . '}}',
                        (string)($value ?? '—'),
                        $text
                    );
                }
            }
        }

        return $text;
    }

    // ── Extraer email desde custom_fields ─────────────────────────────────────

    private function extractEmail(object $row): ?string
    {
        if (empty($row->custom_fields)) {
            return null;
        }

        $cf = json_decode($row->custom_fields, true);
        if (!is_array($cf)) {
            return null;
        }

        // Buscar por nombre de campo conocido (orden de prioridad)
        $knownKeys = ['email', 'correo', 'email_address', 'mail', 'e_mail', 'e-mail'];
        foreach ($knownKeys as $key) {
            if (isset($cf[$key]) && is_string($cf[$key]) && filter_var($cf[$key], FILTER_VALIDATE_EMAIL)) {
                return $cf[$key];
            }
        }

        // Fallback: buscar cualquier valor que sea un email válido
        foreach ($cf as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return null;
    }

    // ── SMTP builder ──────────────────────────────────────────────────────────

    private function buildMailer(EmailAccount $account): array
    {
        $transport = new EsmtpTransport(
            $account->smtp_host,
            $account->smtp_port,
            strtolower($account->smtp_security) === 'ssl'
        );
        $transport->setUsername($account->smtp_username ?: $account->email);
        $transport->setPassword($account->smtp_password ?: $account->password);

        $mailer    = new Mailer($transport);
        $messageId = bin2hex(random_bytes(16)) . '@' . $account->smtp_host;

        return [$mailer, $messageId];
    }
}
