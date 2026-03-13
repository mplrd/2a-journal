<?php

namespace App\Services;

class EmailService
{
    private array $config;
    private string $templateDir;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->templateDir = dirname(__DIR__, 2) . '/templates/emails';
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

    private function send(string $to, string $subject, string $htmlBody): void
    {
        if (!$this->config['enabled']) {
            error_log("[EmailService] To: $to | Subject: $subject");
            error_log("[EmailService] HTML:\n$htmlBody");
            return;
        }

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            "From: {$this->config['from_name']} <{$this->config['from_address']}>",
        ]);

        @mail($to, $subject, $htmlBody, $headers);
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

        $layout = file_get_contents("{$this->templateDir}/layout.html");

        return str_replace(
            ['{{title}}', '{{content}}', '{{app_name}}'],
            [$title, $content, $appName],
            $layout
        );
    }
}
