<?php

namespace App\Services;

class EmailService
{
    private const VALID_DRIVERS = ['log', 'resend'];
    private const RESEND_API_URL = 'https://api.resend.com/emails';

    private array $config;
    private string $templateDir;
    private ?PlatformSettingsService $platformSettings;

    public function __construct(array $config, ?PlatformSettingsService $platformSettings = null)
    {
        $driver = $config['driver'] ?? 'log';
        if (!in_array($driver, self::VALID_DRIVERS, true)) {
            throw new \InvalidArgumentException("Invalid mail driver '$driver'. Valid: " . implode(', ', self::VALID_DRIVERS));
        }
        if ($driver === 'resend' && empty($config['resend_api_key'])) {
            throw new \InvalidArgumentException('RESEND_API_KEY is required when using the resend mail driver');
        }

        $this->config = $config;
        $this->platformSettings = $platformSettings;
        $this->templateDir = dirname(__DIR__, 2) . '/templates/emails';
    }

    /**
     * `enabled` resolution: DB > env (via config) > false. Read at send-time
     * so admin BO toggles take effect without restarting the api.
     */
    private function isEnabled(): bool
    {
        if ($this->platformSettings !== null) {
            $value = $this->platformSettings->resolve('mail_enabled');
            if ($value !== null) {
                return (bool) $value;
            }
        }
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * `from_address` resolution: DB > env (via config) > empty.
     * Returned as-is; the caller is responsible for the final formatting.
     */
    private function fromAddress(): string
    {
        if ($this->platformSettings !== null) {
            $value = $this->platformSettings->resolve('mail_from_address');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return (string) ($this->config['from_address'] ?? '');
    }

    public function sendVerificationEmail(string $toEmail, string $token, string $locale = 'en'): void
    {
        $url = $this->config['frontend_url'] . '/verify-email?token=' . urlencode($token);
        $subject = $locale === 'fr' ? 'Vérifiez votre adresse email' : 'Verify your email address';
        $title = $locale === 'fr' ? 'Vérifiez votre adresse email' : 'Verify your email address';

        $content = $this->loadTemplate('verification', $locale, ['url' => $url]);
        $body = $this->wrapLayout($title, $content);
        $this->send($toEmail, $subject, $body);
    }

    public function sendPasswordResetEmail(string $toEmail, string $token, string $locale = 'en'): void
    {
        $url = $this->config['frontend_url'] . '/reset-password?token=' . urlencode($token);
        $subject = $locale === 'fr' ? 'Réinitialisation de votre mot de passe' : 'Reset your password';
        $title = $locale === 'fr' ? 'Réinitialisation de mot de passe' : 'Password reset';

        $content = $this->loadTemplate('password-reset', $locale, ['url' => $url]);
        $body = $this->wrapLayout($title, $content);
        $this->send($toEmail, $subject, $body);
    }

    public function sendAccountLockedEmail(string $toEmail, string $locale = 'en'): void
    {
        $subject = $locale === 'fr' ? 'Compte verrouillé - Tentatives de connexion suspectes' : 'Account locked - Suspicious login attempts';
        $title = $locale === 'fr' ? 'Compte verrouillé' : 'Account locked';

        $content = $this->loadTemplate('account-locked', $locale);
        $body = $this->wrapLayout($title, $content);
        $this->send($toEmail, $subject, $body);
    }

    /**
     * DD-approach alert. $type is 'max' or 'daily'. $status is the row produced
     * by DrawdownService::computeForAccount (account_name, currency,
     * max/daily_used_amount/percent, max_drawdown, daily_drawdown).
     */
    public function sendDdAlertEmail(string $toEmail, string $locale, string $type, array $status): void
    {
        $isMax = $type === 'max';
        $usedPercent = $isMax ? ($status['max_used_percent'] ?? 0) : ($status['daily_used_percent'] ?? 0);
        $usedAmount = $isMax ? ($status['max_used_amount'] ?? 0) : ($status['daily_used_amount'] ?? 0);
        $ddTotal = $isMax ? ($status['max_drawdown'] ?? 0) : ($status['daily_drawdown'] ?? 0);

        if ($locale === 'fr') {
            $ddLabel = $isMax ? 'drawdown maximum' : 'drawdown journalier';
            $subject = "Alerte {$ddLabel} — compte {$status['account_name']}";
            $title = $isMax ? 'Approche du drawdown maximum' : 'Approche du drawdown journalier';
        } else {
            $ddLabel = $isMax ? 'max drawdown' : 'daily drawdown';
            $subject = "{$ddLabel} alert — account {$status['account_name']}";
            $title = $isMax ? 'Max drawdown approaching' : 'Daily drawdown approaching';
        }

        $content = $this->loadTemplate('dd-alert', $locale, [
            'dd_label' => $ddLabel,
            'account_name' => (string) $status['account_name'],
            'used_percent' => (string) $usedPercent,
            'used_amount' => number_format((float) $usedAmount, 2, '.', ''),
            'dd_total' => number_format((float) $ddTotal, 2, '.', ''),
            'currency' => (string) ($status['currency'] ?? ''),
        ]);
        $body = $this->wrapLayout($title, $content);
        $this->send($toEmail, $subject, $body);
    }

    /**
     * Notify an admin that a new support ticket was opened. $ticket is a row
     * from support_tickets (id, type, subject, ...).
     */
    public function sendTicketCreatedToAdmin(string $toEmail, array $ticket, string $locale = 'en'): void
    {
        $typeLabel = $this->ticketTypeLabel((string) $ticket['type'], $locale);
        $subject = $locale === 'fr'
            ? "Nouveau ticket #{$ticket['id']} — {$ticket['subject']}"
            : "New ticket #{$ticket['id']} — {$ticket['subject']}";
        $title = $locale === 'fr' ? 'Nouveau ticket de support' : 'New support ticket';

        $content = $this->loadTemplate('ticket-created-admin', $locale, [
            'ticket_id' => (string) $ticket['id'],
            'type' => $typeLabel,
            'subject' => (string) $ticket['subject'],
            'url' => $this->ticketUrl((int) $ticket['id']),
        ]);
        $this->send($toEmail, $subject, $this->wrapLayout($title, $content));
    }

    /**
     * Notify the other party that a reply was posted on a ticket.
     * $fromAdmin = true → the message came from an admin (recipient is the
     * ticket creator); false → it came from the user (recipient is an admin).
     */
    public function sendTicketReplyEmail(string $toEmail, array $ticket, string $locale = 'en', bool $fromAdmin = false): void
    {
        if ($locale === 'fr') {
            $subject = "Nouvelle réponse — ticket #{$ticket['id']}";
            $title = $fromAdmin ? 'Réponse du support' : 'Nouvelle réponse utilisateur';
        } else {
            $subject = "New reply — ticket #{$ticket['id']}";
            $title = $fromAdmin ? 'Support replied' : 'New user reply';
        }

        $content = $this->loadTemplate('ticket-reply', $locale, [
            'ticket_id' => (string) $ticket['id'],
            'subject' => (string) $ticket['subject'],
            'url' => $this->ticketUrl((int) $ticket['id']),
        ]);
        $this->send($toEmail, $subject, $this->wrapLayout($title, $content));
    }

    /** Notify the ticket creator that an admin changed the ticket status. */
    public function sendTicketStatusChangedEmail(string $toEmail, array $ticket, string $oldStatus, string $newStatus, string $locale = 'en'): void
    {
        $subject = $locale === 'fr'
            ? "Statut mis à jour — ticket #{$ticket['id']}"
            : "Status updated — ticket #{$ticket['id']}";
        $title = $locale === 'fr' ? 'Statut du ticket mis à jour' : 'Ticket status updated';

        $content = $this->loadTemplate('ticket-status-changed', $locale, [
            'ticket_id' => (string) $ticket['id'],
            'subject' => (string) $ticket['subject'],
            'old_status' => $this->ticketStatusLabel($oldStatus, $locale),
            'new_status' => $this->ticketStatusLabel($newStatus, $locale),
            'url' => $this->ticketUrl((int) $ticket['id']),
        ]);
        $this->send($toEmail, $subject, $this->wrapLayout($title, $content));
    }

    private function ticketUrl(int $ticketId): string
    {
        return rtrim((string) ($this->config['frontend_url'] ?? ''), '/') . '/support?ticket=' . $ticketId;
    }

    private function ticketTypeLabel(string $type, string $locale): string
    {
        $labels = [
            'fr' => ['SUPPORT' => 'Support', 'BUG' => 'Bug', 'FEATURE' => 'Évolution'],
            'en' => ['SUPPORT' => 'Support', 'BUG' => 'Bug', 'FEATURE' => 'Feature request'],
        ];
        $set = $labels[$locale] ?? $labels['en'];

        return $set[$type] ?? $type;
    }

    private function ticketStatusLabel(string $status, string $locale): string
    {
        $labels = [
            'fr' => [
                'OPEN' => 'Ouvert', 'IN_PROGRESS' => 'En cours', 'WAITING_USER' => 'En attente de votre réponse',
                'RESOLVED' => 'Résolu', 'CLOSED' => 'Fermé',
            ],
            'en' => [
                'OPEN' => 'Open', 'IN_PROGRESS' => 'In progress', 'WAITING_USER' => 'Waiting for your reply',
                'RESOLVED' => 'Resolved', 'CLOSED' => 'Closed',
            ],
        ];
        $set = $labels[$locale] ?? $labels['en'];

        return $set[$status] ?? $status;
    }

    private function send(string $to, string $subject, string $htmlBody): void
    {
        if (!$this->isEnabled()) {
            error_log("[EmailService] To: $to | Subject: $subject");
            error_log("[EmailService] HTML:\n$htmlBody");
            return;
        }

        $driver = $this->config['driver'] ?? 'log';

        if ($driver === 'resend') {
            $this->sendViaResend($to, $subject, $htmlBody);
        } else {
            error_log("[EmailService] To: $to | Subject: $subject");
        }
    }

    private function sendViaResend(string $to, string $subject, string $htmlBody): void
    {
        $payload = $this->buildResendPayload($to, $subject, $htmlBody);

        $ch = curl_init(self::RESEND_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['resend_api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[EmailService] Resend cURL error: $error");
            return;
        }

        if ($httpCode >= 400) {
            error_log("[EmailService] Resend API error ($httpCode): $response");
        }
    }

    private function buildResendPayload(string $to, string $subject, string $htmlBody): array
    {
        return [
            'from' => "{$this->config['from_name']} <{$this->fromAddress()}>",
            'to' => [$to],
            'subject' => $subject,
            'html' => $htmlBody,
        ];
    }

    private function loadTemplate(string $name, string $locale, array $variables = []): string
    {
        $file = "{$this->templateDir}/{$name}.{$locale}.html";
        if (!file_exists($file)) {
            $file = "{$this->templateDir}/{$name}.en.html";
        }

        $html = file_get_contents($file);

        foreach ($variables as $key => $value) {
            $html = str_replace("{{" . $key . "}}", htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $html);
        }

        return $html;
    }

    private function wrapLayout(string $title, string $content): string
    {
        $appName = htmlspecialchars($this->config['from_name'], ENT_QUOTES, 'UTF-8');
        $frontendUrl = htmlspecialchars(rtrim((string) ($this->config['frontend_url'] ?? ''), '/'), ENT_QUOTES, 'UTF-8');

        $layout = file_get_contents("{$this->templateDir}/layout.html");

        return str_replace(
            ['{{title}}', '{{content}}', '{{app_name}}', '{{frontend_url}}'],
            [$title, $content, $appName, $frontendUrl],
            $layout
        );
    }
}
