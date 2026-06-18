<?php
/**
 * mail_helper.php  –  Raw SMTP socket mailer, port 25 (no TLS required)
 */

class MailHelper
{
    private string $host;
    private int $port;
    private string $fromAddr;
    private string $fromName;
    private string $username;
    private string $password;
    private int $timeout = 15;

    public function __construct()
    {
        $this->host = (string)(getenv('SMTP_HOST') ?: 'localhost');
        $this->port = (int)(getenv('SMTP_PORT') ?: 25);
        $this->fromAddr = (string)(getenv('SMTP_FROM_ADDR') ?: 'no-reply@inventory-system.local');
        $this->fromName = (string)(getenv('SMTP_FROM_NAME') ?: 'Inventory System');
        $this->username = (string)(getenv('SMTP_USERNAME') ?: '');
        $this->password = (string)(getenv('SMTP_PASSWORD') ?: '');
    }

    /**
     * @return array{success:bool, message:string}
     */
    public function sendRegistrationVerificationCode(
        string $toEmail,
        string $toName,
        string $code,
        DateTimeImmutable $expiry,
        string $verifyUrl
    ): array {
        return $this->send(
            $toEmail,
            $toName,
            'Your Verification Code – Inventory System',
            $this->plainText($toName, $code, $expiry->format('M j, Y g:i A'), $verifyUrl),
            $this->htmlBody($toName, $code, $expiry->format('M j, Y g:i A'), $verifyUrl)
        );
    }

    private function send(string $toEmail, string $toName, string $subject,
                        string $plain, string $html): array
    {
        $errNo = $errStr = 0;
        $sock = @fsockopen($this->host, $this->port, $errNo, $errStr, $this->timeout);
        if ($sock === false) {
            $msg = "SMTP connect {$this->host}:{$this->port} failed ({$errNo}): {$errStr}";
            error_log($msg);
            return ['success' => false, 'message' => $msg];
        }
        stream_set_timeout($sock, $this->timeout);

        try {
            $this->expect($this->read($sock), '220', 'greeting');

            $hn = gethostname() ?: 'localhost';
            $ehlo = $this->cmd($sock, "EHLO {$hn}");
            if (!$this->is($ehlo, '250')) {
                $this->expect($this->cmd($sock, "HELO {$hn}"), '250', 'HELO');
                $ehlo = '';
            }

            if ($this->username !== '') {
                if (str_contains($ehlo, 'AUTH LOGIN')) $this->authLogin($sock);
                elseif (str_contains($ehlo, 'AUTH PLAIN')) $this->authPlain($sock);
            }

            $this->expect($this->cmd($sock, "MAIL FROM:<{$this->fromAddr}>"), '250', 'MAIL FROM');
            $this->expect($this->cmd($sock, "RCPT TO:<{$toEmail}>"), '250', 'RCPT TO');
            $this->expect($this->cmd($sock, 'DATA'), '354', 'DATA');

            fwrite($sock, $this->buildMessage($toEmail, $toName, $subject, $plain, $html) . "\r\n.\r\n");
            $this->expect($this->read($sock), '250', 'message accepted');

            $this->cmd($sock, 'QUIT');

        } catch (\RuntimeException $e) {
            fclose($sock);
            error_log('MailHelper: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }

        fclose($sock);
        return ['success' => true, 'message' => 'Email sent successfully.'];
    }

    /**
     * @param resource $sock
     */
    private function authLogin($sock): void
    {
        $this->expect($this->cmd($sock, 'AUTH LOGIN'), '334', 'AUTH LOGIN');
        $this->expect($this->cmd($sock, base64_encode($this->username)), '334', 'AUTH LOGIN user');
        $this->expect($this->cmd($sock, base64_encode($this->password)), '235', 'AUTH LOGIN pass');
    }

    /**
     * @param resource $sock
     */
    private function authPlain($sock): void
    {
        $cred = base64_encode("\0{$this->username}\0{$this->password}");
        $this->expect($this->cmd($sock, "AUTH PLAIN {$cred}"), '235', 'AUTH PLAIN');
    }

    /**
     * @param resource $sock
     */
    private function read($sock): string
    {
        $r = '';
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            if ($line === false) break;
            $r .= $line;
            if (strlen($line) >= 4 && $line[3] !== '-') break;
        }
        return $r;
    }

    /**
     * @param resource $sock
     */
    private function cmd($sock, string $line): string
    {
        fwrite($sock, $line . "\r\n");
        return $this->read($sock);
    }

    private function is(string $r, string $code): bool
    {
        return str_starts_with(trim($r), $code);
    }

    private function expect(string $r, string $code, string $ctx): void
    {
        if (!$this->is($r, $code))
            throw new \RuntimeException("SMTP [{$ctx}] expected {$code}, got: " . trim($r));
    }

    private function buildMessage(string $toEmail, string $toName, string $subject,
                                string $plain, string $html): string
    {
        $b = '----=_Part_' . bin2hex(random_bytes(8));
        $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';

        $hdr = "Date: " . date('r') . "\r\n";
        $hdr .= "From: {$enc($this->fromName)} <{$this->fromAddr}>\r\n";
        $hdr .= "To: {$enc($toName)} <{$toEmail}>\r\n";
        $hdr .= "Subject: {$enc($subject)}\r\n";
        $hdr .= "MIME-Version: 1.0\r\n";
        $hdr .= "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
        $hdr .= "X-Mailer: InventorySystem/2.0\r\n";

        $body = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plain)) . "\r\n";
        $body .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html)) . "\r\n";
        $body .= "--{$b}--";

        return $hdr . "\r\n" . $body;
    }

    private function plainText(string $name, string $code, string $expiry, string $url): string
    {
        return "Hello {$name},\r\n\r\n"
            . "Your 6-digit verification code is: {$code}\r\n\r\n"
            . "It expires at: {$expiry}\r\n\r\n"
            . "Visit {$url} to activate your account.\r\n\r\n"
            . "If you did not register, ignore this email.\r\n\r\n"
            . "— The Inventory System Team";
    }

    private function htmlBody(string $name, string $code, string $expiry, string $url): string
    {
        $sName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $sExpiry = htmlspecialchars($expiry, ENT_QUOTES, 'UTF-8');
        $sUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        // Individual digit boxes
        $boxes = '';
        foreach (str_split($code) as $d) {
            $boxes .= "<span style='display:inline-block;width:44px;height:52px;line-height:52px;"
                . "text-align:center;font-size:26px;font-weight:800;border:2px solid #e6bc67;"
                . "border-radius:10px;margin:0 3px;color:#1f1a11;background:#fffdf7;'>{$d}</span>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Verify Your Account</title>
</head>
<body style="margin:0;padding:0;background:#f8f3e8;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f3e8;padding:32px 0;">
        <tr>
            <td align="center">
                <table width="560" cellpadding="0" cellspacing="0"
                    style="background:#fff;border-radius:20px;border:1px solid #eadfcb;
                           box-shadow:0 8px 32px rgba(93,67,28,.10);overflow:hidden;max-width:100%;">
                    <tr>
                        <td style="background:linear-gradient(135deg,#c59031,#e6bc67);padding:24px 36px;text-align:center;">
                            <h1 style="margin:0;color:#1f1a11;font-size:20px;font-weight:800;">Inventory System</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:36px 36px 20px;">
                            <h2 style="margin:0 0 10px;font-size:20px;color:#1e1f24;">Hello, {$sName}!</h2>
                            <p style="margin:0 0 22px;color:#6d6458;line-height:1.7;">
                                Use the 6-digit code below to verify your account.<br>
                                It expires at <strong>{$sExpiry}</strong>.
                            </p>
                            <div style="text-align:center;margin:24px 0;">{$boxes}</div>
                            <div style="text-align:center;margin:28px 0 18px;">
                                <a href="{$sUrl}" style="display:inline-block;background:linear-gradient(135deg,#c59031,#e6bc67);
                                   color:#1f1a11;text-decoration:none;font-weight:700;font-size:15px;
                                   padding:14px 36px;border-radius:12px;">Go to Verify Page</a>
                            </div>
                            <p style="margin:0;color:#6d6458;font-size:13px;line-height:1.6;">
                                If you did not create an account, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 36px 26px;border-top:1px solid #eadfcb;">
                            <p style="margin:0;font-size:12px;color:#a0937d;text-align:center;">
                                &copy; {$year} Inventory System &nbsp;&middot;&nbsp; Automated message, do not reply.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}
