<?php
namespace PHPMailer\PHPMailer;

/**
 * PHPMailer - PHP email creation and transport class.
 * Real SMTP Engine with full Debugging (SMTPDebug), SMTPOptions SSL bypass, and RFC 5321 Multiline Handshake.
 */
class PHPMailer
{
    const CHARSET_ASCII = 'us-ascii';
    const CHARSET_ISO88591 = 'iso-8859-1';
    const CHARSET_UTF8 = 'utf-8';

    const CONTENT_TYPE_PLAINTEXT = 'text/plain';
    const CONTENT_TYPE_TEXT_CALENDAR = 'text/calendar';
    const CONTENT_TYPE_TEXT_HTML = 'text/html';
    const CONTENT_TYPE_MULTIPART_ALTERNATIVE = 'multipart/alternative';
    const CONTENT_TYPE_MULTIPART_MIXED = 'multipart/mixed';
    const CONTENT_TYPE_MULTIPART_RELATED = 'multipart/related';

    const ENCODING_7BIT = '7bit';
    const ENCODING_8BIT = '8bit';
    const ENCODING_BASE64 = 'base64';
    const ENCODING_BINARY = 'binary';
    const ENCODING_QUOTED_PRINTABLE = 'quoted-printable';

    const ENCRYPTION_STARTTLS = 'tls';
    const ENCRYPTION_SMTPS = 'ssl';

    public $Priority;
    public $CharSet = self::CHARSET_UTF8;
    public $ContentType = self::CONTENT_TYPE_PLAINTEXT;
    public $Encoding = self::ENCODING_8BIT;
    public $ErrorInfo = '';
    public $From = 'root@localhost';
    public $FromName = 'Root User';
    public $Sender = '';
    public $Subject = '';
    public $Body = '';
    public $AltBody = '';
    public $WordWrap = 0;
    public $Mailer = 'smtp';
    public $Host = 'smtp.gmail.com';
    public $Port = 587;
    public $SMTPSecure = self::ENCRYPTION_STARTTLS;
    public $SMTPAutoTLS = true;
    public $SMTPAuth = true;
    public $Username = '';
    public $Password = '';
    public $Timeout = 30;
    public $SMTPDebug = 0;
    public $Debugoutput = 'echo';
    public $SMTPOptions = [];

    protected $to = [];
    protected $cc = [];
    protected $bcc = [];
    protected $ReplyTo = [];
    protected $exceptions = true;

    public function __construct($exceptions = true)
    {
        $this->exceptions = (bool) $exceptions;
    }

    public function isSMTP()
    {
        $this->Mailer = 'smtp';
    }

    public function isHTML($isHtml = true)
    {
        if ($isHtml) {
            $this->ContentType = self::CONTENT_TYPE_TEXT_HTML;
        } else {
            $this->ContentType = self::CONTENT_TYPE_PLAINTEXT;
        }
    }

    public function setFrom($address, $name = '', $auto = true)
    {
        $this->From = $address;
        $this->FromName = $name;
        if ($auto && empty($this->Sender)) {
            $this->Sender = $address;
        }
        return true;
    }

    public function addAddress($address, $name = '')
    {
        $this->to[] = [$address, $name];
        return true;
    }

    public function addReplyTo($address, $name = '')
    {
        $this->ReplyTo[] = [$address, $name];
        return true;
    }

    public function addCC($address, $name = '')
    {
        $this->cc[] = [$address, $name];
        return true;
    }

    public function addBCC($address, $name = '')
    {
        $this->bcc[] = [$address, $name];
        return true;
    }

    public function clearAddresses()
    {
        $this->to = [];
    }

    protected function edebug($str)
    {
        if ($this->SMTPDebug <= 0) {
            return;
        }
        $line = date('Y-m-d H:i:s') . "\t" . $str . "\n";
        if (is_callable($this->Debugoutput)) {
            call_user_func($this->Debugoutput, $line, $this->SMTPDebug);
        } else {
            echo $line;
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        }
    }

    /**
     * อ่าน Response แบบ Multiline ตาม RFC 5321 (เช่น 250- ... 250 OK)
     */
    protected function getSmtpResponse($fp)
    {
        $response = '';
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                break;
            }
            $response .= $line;
            $this->edebug("SERVER -> CLIENT: " . trim($line));
            // ถ้าขึ้นต้นด้วย รหัส 3 หลักตามด้วยช่องว่าง ถือเป็นบรรทัดสุดท้ายของการตอบกลับ
            if (preg_match('/^[0-9]{3} /', $line)) {
                break;
            }
        }
        return $response;
    }

    /**
     * ส่งคำสั่ง SMTP Command พร้อมอ่าน Response
     */
    protected function sendSmtpCommand($fp, $command, $hideInDebug = false)
    {
        $this->edebug("CLIENT -> SERVER: " . ($hideInDebug ? '********' : trim($command)));
        fputs($fp, $command . "\r\n");
        return $this->getSmtpResponse($fp);
    }

    /**
     * ส่งข้อความผ่านทาง SMTP Server พร้อมการตรวจสอบข้อผิดพลาดจริง
     */
    public function send()
    {
        try {
            if (empty($this->to)) {
                throw new Exception('You must provide at least one recipient email address.');
            }

            if (empty($this->Host)) {
                throw new Exception('SMTP Host is not specified.');
            }

            if (empty($this->Username) || empty($this->Password)) {
                throw new Exception('SMTP Username or Password (App Password) is empty.');
            }

            // เตรียม SSL Stream Context Options
            $sslOptions = [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ];
            if (!empty($this->SMTPOptions['ssl']) && is_array($this->SMTPOptions['ssl'])) {
                $sslOptions = array_merge($sslOptions, $this->SMTPOptions['ssl']);
            }
            $context = stream_context_create(['ssl' => $sslOptions]);

            $isSSL = ($this->SMTPSecure === 'ssl' || $this->Port == 465);
            $prefix = $isSSL ? 'ssl://' : '';
            $connectHost = $prefix . $this->Host . ':' . $this->Port;

            $this->edebug("Connection: opening to {$connectHost}, timeout={$this->Timeout}, options=" . json_encode($sslOptions));

            $errno  = 0;
            $errstr = '';
            $fp = @stream_socket_client($connectHost, $errno, $errstr, $this->Timeout, STREAM_CLIENT_CONNECT, $context);

            if (!$fp) {
                // หากเชื่อมต่อผ่าน Hostname ไม่ผ่าน (เช่น มีปัญหา DNS/IPv6 บน macOS) ให้ลองแปลงเป็น IPv4 แล้วเชื่อมต่อซ้ำ
                if ($this->Host === 'smtp.gmail.com') {
                    $ipv4 = gethostbyname('smtp.gmail.com');
                    if (!empty($ipv4) && $ipv4 !== 'smtp.gmail.com') {
                        $this->edebug("Connection: Hostname connect failed, retrying with IPv4 {$ipv4}:{$this->Port}...");
                        $connectHostIpv4 = $prefix . $ipv4 . ':' . $this->Port;
                        $fp = @stream_socket_client($connectHostIpv4, $errno, $errstr, $this->Timeout, STREAM_CLIENT_CONNECT, $context);
                    }
                }
            }

            if (!$fp) {
                throw new Exception("SMTP Error: Could not connect to SMTP host {$this->Host}:{$this->Port}. (Socket error {$errno}: {$errstr})");
            }

            // ตั้งค่า timeout ของ socket
            stream_set_timeout($fp, $this->Timeout);

            // 1. อ่าน Server Banner
            $banner = $this->getSmtpResponse($fp);
            if (substr($banner, 0, 3) !== '220') {
                fclose($fp);
                throw new Exception("SMTP Error: Server rejected connection. Banner: " . trim($banner));
            }

            // 2. ส่ง EHLO
            $clientHost = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : (gethostname() ?: 'localhost');
            $ehloResp = $this->sendSmtpCommand($fp, "EHLO " . $clientHost);
            if (substr($ehloResp, 0, 3) !== '250') {
                // ลอง fallback ไป HELO
                $ehloResp = $this->sendSmtpCommand($fp, "HELO " . $clientHost);
                if (substr($ehloResp, 0, 3) !== '250') {
                    fclose($fp);
                    throw new Exception("SMTP Error: EHLO/HELO rejected: " . trim($ehloResp));
                }
            }

            // 3. เริ่มต้น STARTTLS (ถ้าเป็นพอร์ต 587 หรือเลือก TLS)
            if (!$isSSL && ($this->SMTPSecure === 'tls' || $this->Port == 587)) {
                $tlsResp = $this->sendSmtpCommand($fp, "STARTTLS");
                if (substr($tlsResp, 0, 3) !== '220') {
                    fclose($fp);
                    throw new Exception("SMTP Error: STARTTLS rejected: " . trim($tlsResp));
                }

                $this->edebug("Connection: Starting TLS crypto handshake...");
                $cryptoOk = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$cryptoOk) {
                    fclose($fp);
                    throw new Exception("SMTP Error: TLS Handshake failed (stream_socket_enable_crypto failed). กรุณาตรวจสอบ OpenSSL บนเครื่อง");
                }
                $this->edebug("Connection: TLS handshake successful!");

                // ส่ง EHLO อีกครั้งหลังเปิด TLS
                $ehloResp = $this->sendSmtpCommand($fp, "EHLO " . $clientHost);
                if (substr($ehloResp, 0, 3) !== '250') {
                    fclose($fp);
                    throw new Exception("SMTP Error: EHLO after TLS failed: " . trim($ehloResp));
                }
            }

            // 4. เข้าสู่ระบบด้วย AUTH LOGIN
            if ($this->SMTPAuth) {
                $authInit = $this->sendSmtpCommand($fp, "AUTH LOGIN");
                if (substr($authInit, 0, 3) !== '334') {
                    fclose($fp);
                    throw new Exception("SMTP Error: AUTH LOGIN not supported or rejected: " . trim($authInit));
                }

                // ส่ง Username (base64)
                $userResp = $this->sendSmtpCommand($fp, base64_encode($this->Username));
                if (substr($userResp, 0, 3) !== '334') {
                    fclose($fp);
                    throw new Exception("SMTP Error: Username not accepted: " . trim($userResp));
                }

                // ส่ง Password (base64)
                $passResp = $this->sendSmtpCommand($fp, base64_encode($this->Password), true);
                if (substr($passResp, 0, 3) !== '235') {
                    fclose($fp);
                    throw new Exception("SMTP Error: Could not authenticate. Password not accepted (App Password อาจไม่ถูกต้อง): " . trim($passResp));
                }
                $this->edebug("Authentication: Successfully authenticated as {$this->Username}!");
            }

            // 5. MAIL FROM
            $fromAddr = !empty($this->From) ? $this->From : $this->Username;
            $mailFromResp = $this->sendSmtpCommand($fp, "MAIL FROM:<{$fromAddr}>");
            if (substr($mailFromResp, 0, 3) !== '250') {
                fclose($fp);
                throw new Exception("SMTP Error: MAIL FROM rejected: " . trim($mailFromResp));
            }

            // 6. RCPT TO
            foreach ($this->to as $recipient) {
                $rcptResp = $this->sendSmtpCommand($fp, "RCPT TO:<{$recipient[0]}>");
                if (substr($rcptResp, 0, 3) !== '250') {
                    fclose($fp);
                    throw new Exception("SMTP Error: RCPT TO <{$recipient[0]}> rejected: " . trim($rcptResp));
                }
            }

            // 7. DATA
            $dataResp = $this->sendSmtpCommand($fp, "DATA");
            if (substr($dataResp, 0, 3) !== '354') {
                fclose($fp);
                throw new Exception("SMTP Error: DATA rejected: " . trim($dataResp));
            }

            // 8. ประกอบและส่ง Header + Body
            $recipientNames = [];
            foreach ($this->to as $recipient) {
                $recipientNames[] = !empty($recipient[1]) ? "=?UTF-8?B?" . base64_encode($recipient[1]) . "?= <{$recipient[0]}>" : "<{$recipient[0]}>";
            }
            $toHeader = implode(', ', $recipientNames);

            $headers = "Date: " . date('r') . "\r\n";
            $headers .= "To: {$toHeader}\r\n";
            $headers .= "From: =?UTF-8?B?" . base64_encode($this->FromName) . "?= <{$fromAddr}>\r\n";
            if (!empty($this->ReplyTo)) {
                $headers .= "Reply-To: <{$this->ReplyTo[0][0]}>\r\n";
            }
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($this->Subject) . "?=\r\n";
            $headers .= "Message-ID: <" . md5(uniqid(time())) . "@" . $this->Host . ">\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: {$this->ContentType}; charset={$this->CharSet}\r\n";
            $headers .= "Content-Transfer-Encoding: {$this->Encoding}\r\n\r\n";

            $this->edebug("Sending Message Body...");
            fputs($fp, $headers . $this->Body . "\r\n.\r\n");
            $sendResp = $this->getSmtpResponse($fp);

            if (substr($sendResp, 0, 3) !== '250') {
                fclose($fp);
                throw new Exception("SMTP Error: Message sending rejected by server: " . trim($sendResp));
            }

            // 9. QUIT
            $this->sendSmtpCommand($fp, "QUIT");
            fclose($fp);

            // บันทึก Log การส่งอีเมลสำเร็จ
            $logDir = dirname(__DIR__, 4) . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logFile = $logDir . '/sent_emails.log';
            $recipientsStr = implode(', ', array_map(function($r) { return "{$r[1]} <{$r[0]}>"; }, $this->to));
            
            $logEntry = "========================================================\n";
            $logEntry .= "TIMESTAMP : " . date('Y-m-d H:i:s') . "\n";
            $logEntry .= "FROM      : {$this->FromName} <{$fromAddr}>\n";
            $logEntry .= "TO        : {$recipientsStr}\n";
            $logEntry .= "SUBJECT   : {$this->Subject}\n";
            $logEntry .= "STATUS    : SENT_VIA_REAL_SMTP_SUCCESS (Response: " . trim($sendResp) . ")\n";
            $logEntry .= "========================================================\n\n";
            @file_put_contents($logFile, $logEntry, FILE_APPEND);

            return true;
        } catch (\Exception $e) {
            $this->ErrorInfo = $e->getMessage();
            $this->edebug("SMTP ERROR: " . $this->ErrorInfo);
            if ($this->exceptions) {
                throw new Exception($this->ErrorInfo);
            }
            return false;
        }
    }
}
