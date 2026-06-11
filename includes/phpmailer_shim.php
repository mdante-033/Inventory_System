<?php

if (!class_exists('ShimPHPMailerException')) {
    class ShimPHPMailerException extends \Exception {}
    class_alias('ShimPHPMailerException', 'PHPMailer\\PHPMailer\\Exception');
}

if (!class_exists('ShimPHPMailerSMTP')) {
    class ShimPHPMailerSMTP
    {
        const DEBUG_OFF = 0;
    }
    class_alias('ShimPHPMailerSMTP', 'PHPMailer\\PHPMailer\\SMTP');
}

if (!class_exists('ShimPHPMailer')) {
    class ShimPHPMailer
    {
        public int $SMTPDebug = 0;
        public string $Host = '';
        public bool $SMTPAuth = false;
        public string $Username = '';
        public string $Password = '';
        public string $SMTPSecure = '';
        public int $Port = 25;
        public string $From = '';
        public string $FromName = '';
        public string $CharSet = 'UTF-8';
        public string $Subject = '';
        public string $Body = '';
        public string $AltBody = '';
        public string $ErrorInfo = '';

        private array $to = [];

        public function __construct($exceptions = null)
        {
            // Initialize with default values
        }

        public function isSMTP(): void
        {
            // Implementation
        }

        public function setFrom(string $email, string $name = ''): void
        {
            $this->From = $email;
            $this->FromName = $name;
        }

        public function addReplyTo(string $address, string $name = ''): void
        {
            // Implementation
        }

        public function isHTML(bool $bool): void
        {
            // Implementation
        }

        public function clearAddresses(): void
        {
            $this->to = [];
        }

        public function addAddress(string $address): void
        {
            $this->to[] = $address;
        }

        public function send(): bool
        {
            $to = implode(',', $this->to);
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset={$this->CharSet}\r\n";
            if (!empty($this->From)) {
                $headers .= 'From: ' . ($this->FromName ?: $this->From) . " <{$this->From}>\r\n";
            }

            $sent = @mail($to, $this->Subject ?? '', $this->Body ?? '', $headers);
            if (!$sent) {
                $this->ErrorInfo = 'PHP mail() failed in PHPMailer shim';
                throw new ShimPHPMailerException($this->ErrorInfo);
            }

            return true;
        }
    }
    class_alias('ShimPHPMailer', 'PHPMailer\\PHPMailer\\PHPMailer');
}
